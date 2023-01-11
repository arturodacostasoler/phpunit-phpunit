<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\TextUI;

use const PHP_EOL;
use function array_keys;
use function is_readable;
use function printf;
use function realpath;
use function sprintf;
use function trim;
use PHPUnit\Event;
use PHPUnit\Event\Facade;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\Extension\ExtensionBootstrapper;
use PHPUnit\Runner\Extension\Facade as ExtensionFacade;
use PHPUnit\Runner\Version;
use PHPUnit\TextUI\CliArguments\Builder;
use PHPUnit\TextUI\CliArguments\Exception as ArgumentsException;
use PHPUnit\TextUI\Command\AtLeastVersionCommand;
use PHPUnit\TextUI\Command\GenerateConfigurationCommand;
use PHPUnit\TextUI\Command\ListGroupsCommand;
use PHPUnit\TextUI\Command\ListTestsAsTextCommand;
use PHPUnit\TextUI\Command\ListTestsAsXmlCommand;
use PHPUnit\TextUI\Command\ListTestSuitesCommand;
use PHPUnit\TextUI\Command\MigrateConfigurationCommand;
use PHPUnit\TextUI\Command\ShowHelpCommand;
use PHPUnit\TextUI\Command\ShowVersionCommand;
use PHPUnit\TextUI\Command\VersionCheckCommand;
use PHPUnit\TextUI\Command\WarmCodeCoverageCacheCommand;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\Registry;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\XmlConfiguration\ConfigurationFileFinder;
use PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration;
use PHPUnit\TextUI\XmlConfiguration\Loader;
use Throwable;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Application
{
    private const SUCCESS_EXIT = 0;

    private const FAILURE_EXIT = 1;

    private const EXCEPTION_EXIT = 2;

    private const CRASH_EXIT = 255;

    /**
     * @psalm-var array<string,mixed>
     */
    private array $longOptions         = [];
    private bool $versionStringPrinted = false;

    /**
     * @throws Exception
     */
    public static function main(bool $exit = true): int
    {
        try {
            return (new self)->run($_SERVER['argv'], $exit);
        } catch (Throwable $t) {
            throw new RuntimeException(
                $t->getMessage(),
                (int) $t->getCode(),
                $t
            );
        }
    }

    /**
     * @throws \PHPUnit\Framework\Exception
     * @throws \PHPUnit\Runner\Exception
     * @throws \PHPUnit\TextUI\XmlConfiguration\Exception
     * @throws ArgumentsException
     * @throws Event\RuntimeException
     * @throws Exception
     */
    public function run(array $argv, bool $exit = true): int
    {
        Event\Facade::emitter()->testRunnerStarted();

        $suite = $this->handleArguments($argv);

        Event\Facade::emitter()->testSuiteLoaded(Event\TestSuite\TestSuite::fromTestSuite($suite));

        $runner = new TestRunner;

        try {
            $result = $runner->run($suite);

            $shellExitCode = (new ShellExitCodeCalculator)->calculate(Registry::get(), $result);
        } catch (Throwable $t) {
            $message = $t->getMessage();

            if (empty(trim($message))) {
                $message = '(no message)';
            }

            printf(
                '%s%sAn error occurred inside PHPUnit.%s%sMessage:  %s%sLocation: %s:%d%s%s%s%s',
                PHP_EOL,
                PHP_EOL,
                PHP_EOL,
                PHP_EOL,
                $message,
                PHP_EOL,
                $t->getFile(),
                $t->getLine(),
                PHP_EOL,
                PHP_EOL,
                $t->getTraceAsString(),
                PHP_EOL
            );

            exit(self::CRASH_EXIT);
        }

        Event\Facade::emitter()->testRunnerFinished();

        if ($exit) {
            exit($shellExitCode);
        }

        return $shellExitCode;
    }

    /**
     * @throws \PHPUnit\Framework\Exception
     * @throws \PHPUnit\Runner\Exception
     * @throws \PHPUnit\TextUI\XmlConfiguration\Exception
     * @throws ArgumentsException
     * @throws Exception
     */
    private function handleArguments(array $argv): TestSuite
    {
        try {
            $cliConfiguration = (new Builder)->fromParameters($argv, array_keys($this->longOptions));
        } catch (ArgumentsException $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }

        if ($cliConfiguration->hasGenerateConfiguration() && $cliConfiguration->generateConfiguration()) {
            $this->execute(new GenerateConfigurationCommand);
        }

        if ($cliConfiguration->hasAtLeastVersion()) {
            $this->execute(new AtLeastVersionCommand($cliConfiguration->atLeastVersion()));
        }

        if ($cliConfiguration->hasVersion() && $cliConfiguration->version()) {
            $this->execute(new ShowVersionCommand);
        }

        if ($cliConfiguration->hasCheckVersion() && $cliConfiguration->checkVersion()) {
            $this->execute(new VersionCheckCommand);
        }

        if ($cliConfiguration->hasHelp()) {
            $this->execute(new ShowHelpCommand(true));
        }

        if ($cliConfiguration->hasUnrecognizedOrderBy()) {
            $this->exitWithErrorMessage(
                sprintf(
                    'unrecognized --order-by option: %s',
                    $cliConfiguration->unrecognizedOrderBy()
                )
            );
        }

        $configurationFile = (new ConfigurationFileFinder)->find($cliConfiguration);

        if ($configurationFile) {
            try {
                $xmlConfiguration = (new Loader)->load($configurationFile);
            } catch (Throwable $e) {
                print $e->getMessage() . PHP_EOL;

                exit(self::FAILURE_EXIT);
            }
        }

        if ($cliConfiguration->hasMigrateConfiguration() && $cliConfiguration->migrateConfiguration()) {
            if (!$configurationFile) {
                print 'No configuration file found to migrate.' . PHP_EOL;

                exit(self::EXCEPTION_EXIT);
            }

            $this->execute(new MigrateConfigurationCommand(realpath($configurationFile)));
        }

        $xmlConfiguration = $xmlConfiguration ?? DefaultConfiguration::create();

        $configuration = Registry::init(
            $cliConfiguration,
            $xmlConfiguration
        );

        (new PhpHandler)->handle(
            $configuration->includePaths(),
            $configuration->iniSettings(),
            $configuration->constants(),
            $configuration->globalVariables(),
            $configuration->envVariables(),
            $configuration->postVariables(),
            $configuration->getVariables(),
            $configuration->cookieVariables(),
            $configuration->serverVariables(),
            $configuration->filesVariables(),
            $configuration->requestVariables(),
        );

        Event\Facade::emitter()->testRunnerConfigured($configuration);

        if ($configuration->hasBootstrap()) {
            $this->handleBootstrap($configuration->bootstrap());
        }

        $testSuite = $this->buildTestSuite($configuration);

        $this->bootstrapExtensions($configuration);

        if ($configuration->hasCoverageReport() || $cliConfiguration->hasWarmCoverageCache()) {
            CodeCoverageFilterRegistry::init($configuration);
        }

        if ($cliConfiguration->hasWarmCoverageCache() && $cliConfiguration->warmCoverageCache()) {
            $this->execute(new WarmCodeCoverageCacheCommand);
        }

        if ($cliConfiguration->hasListGroups() && $cliConfiguration->listGroups()) {
            $this->execute(new ListGroupsCommand($testSuite));
        }

        if ($cliConfiguration->hasListSuites() && $cliConfiguration->listSuites()) {
            $this->execute(new ListTestSuitesCommand($xmlConfiguration->testSuite()));
        }

        if ($cliConfiguration->hasListTests() && $cliConfiguration->listTests()) {
            $this->execute(new ListTestsAsTextCommand($testSuite));
        }

        if ($cliConfiguration->hasListTestsXml() && $cliConfiguration->listTestsXml()) {
            $this->execute(
                new ListTestsAsXmlCommand(
                    $cliConfiguration->listTestsXml(),
                    $testSuite
                )
            );
        }

        if ($testSuite->isEmpty() && !$cliConfiguration->hasArgument() && !$xmlConfiguration->phpunit()->hasDefaultTestSuite()) {
            $this->execute(new ShowHelpCommand(false));
        }

        return $testSuite;
    }

    private function printVersionString(): void
    {
        if ($this->versionStringPrinted) {
            return;
        }

        print Version::getVersionString() . PHP_EOL . PHP_EOL;

        $this->versionStringPrinted = true;
    }

    private function exitWithErrorMessage(string $message): never
    {
        $this->printVersionString();

        print $message . PHP_EOL;

        exit(self::FAILURE_EXIT);
    }

    private function execute(Command\Command $command): never
    {
        $this->printVersionString();

        $result = $command->execute();

        print $result->output();

        if ($result->wasSuccessful()) {
            exit(self::SUCCESS_EXIT);
        }

        exit(self::EXCEPTION_EXIT);
    }

    private function handleBootstrap(string $filename): void
    {
        if (!is_readable($filename)) {
            $this->exitWithErrorMessage(
                sprintf(
                    'Cannot open bootstrap script "%s"',
                    $filename
                )
            );
        }

        try {
            include_once $filename;
        } catch (Throwable $t) {
            $this->exitWithErrorMessage(
                sprintf(
                    'Error in bootstrap script: %s:%s%s%s%s',
                    $t::class,
                    PHP_EOL,
                    $t->getMessage(),
                    PHP_EOL,
                    $t->getTraceAsString()
                )
            );
        }

        Facade::emitter()->testRunnerBootstrapFinished($filename);
    }

    private function bootstrapExtensions(Configuration $configuration): void
    {
        $extensionBootstrapper = new ExtensionBootstrapper(
            $configuration,
            new ExtensionFacade
        );

        foreach ($configuration->extensionBootstrappers() as $bootstrapper) {
            try {
                $extensionBootstrapper->bootstrap(
                    $bootstrapper['className'],
                    $bootstrapper['parameters']
                );
            } catch (\PHPUnit\Runner\Exception $e) {
                $this->exitWithErrorMessage(
                    sprintf(
                        'Error while bootstrapping extension: %s',
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function buildTestSuite(Configuration $configuration): TestSuite
    {
        try {
            return (new TestSuiteBuilder)->build($configuration);
        } catch (Exception $e) {
            $this->exitWithErrorMessage($e->getMessage());
        }
    }
}
