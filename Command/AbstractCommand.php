<?php

/**
 * This file is part of the PropelBundle package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propel\PropelBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @author Kévin Gomez <contact@kevingomez.fr>
 */
abstract class AbstractCommand extends ContainerAwareCommand
{
    /**
     * @var string
     */
    protected $cacheDir = null;

    /**
     * @var Symfony\Component\HttpKernel\Bundle\BundleInterface
     */
    protected $bundle = null;

    /**
     * @var InputInterface
     */
    protected $input;

    use FormattingHelpers;

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $kernel = $this->getApplication()->getKernel();

        $this->input = $input;
        $this->cacheDir = $kernel->getCacheDir().'/propel';

        $this->checkConfiguration();

        if ($input->hasArgument('bundle') && '@' === substr($input->getArgument('bundle'), 0, 1)) {
            $this->bundle = $this
                ->getContainer()
                ->get('kernel')
                ->getBundle(substr($input->getArgument('bundle'), 1));
        }
    }

    /**
     * Create all the files needed by Propel's commands.
     */
    protected function setupBuildTimeFiles()
    {
        $kernel = $this->getApplication()->getKernel();

        $fs = new Filesystem();
        $fs->mkdir($this->cacheDir);

        // collect all schemas
        $this->copySchemas($kernel, $this->cacheDir);

        // build.properties
        $this->createBuildPropertiesFile($kernel, $this->cacheDir.'/build.properties');

        // buildtime-conf.xml
        $this->createBuildTimeFile($this->cacheDir.'/buildtime-conf.xml');
    }

    /**
     * @param KernelInterface $kernel   The application kernel.
     * @param string          $cacheDir The directory in which the schemas will
     *                                  be copied.
     */
    protected function copySchemas(KernelInterface $kernel, $cacheDir)
    {
        $filesystem = new Filesystem();
        $base = ltrim(realpath($kernel->getRootDir().'/..'), DIRECTORY_SEPARATOR);

        $finalSchemas = $this->getFinalSchemas($kernel, $this->bundle);
        foreach ($finalSchemas as $schema) {
            list($bundle, $finalSchema) = $schema;
            $packagePrefix = $this->getPackagePrefix($bundle, $base);

            $tempSchema = $bundle->getName().'-'.$finalSchema->getBaseName();
            $this->tempSchemas[$tempSchema] = array(
                'bundle'    => $bundle->getName(),
                'basename'  => $finalSchema->getBaseName(),
                'path'      => $finalSchema->getPathname(),
            );

            $file = $cacheDir.DIRECTORY_SEPARATOR.$tempSchema;
            $filesystem->copy((string) $finalSchema, $file, true);

            // the package needs to be set absolute
            // besides, the automated namespace to package conversion has
            // not taken place yet so it needs to be done manually
            $database = simplexml_load_file($file);

            if (isset($database['package'])) {
                // Do not use the prefix!
                // This is used to override the package resulting from namespace conversion.
                $database['package'] = $database['package'];
            } elseif (isset($database['namespace'])) {
                $database['package'] = $packagePrefix . str_replace('\\', '.', $database['namespace']);
            } else {
                throw new \RuntimeException(
                    sprintf('%s : Please define a `package` attribute or a `namespace` attribute for schema `%s`',
                        $bundle->getName(), $finalSchema->getBaseName())
                );
            }

            if ($this->input->hasOption('connection')) {
                $connections = $this->input->getOption('connection') ?: array($this->getContainer()->getParameter('propel.dbal.default_connection'));

                if (!in_array((string) $database['name'], $connections)) {
                    // we skip this schema because the connection name doesn't
                    // match the input values
                    unset($this->tempSchemas[$tempSchema]);
                    $filesystem->remove($file);
                    continue;
                }
            }

            foreach ($database->table as $table) {
                if (isset($table['package'])) {
                    $table['package'] = $table['package'];
                } elseif (isset($table['namespace'])) {
                    $table['package'] = $packagePrefix . str_replace('\\', '.', $table['namespace']);
                } else {
                    $table['package'] = $database['package'];
                }
            }

            file_put_contents($file, $database->asXML());
        }
    }

    /**
     * Return a list of final schema files that will be processed.
     *
     * @param KernelInterface $kernel The application kernel.
     * @param BundleInterface $bundle If given, only the bundle's schemas will
     *                                be returned.
     *
     * @return array A list of schemas.
     */
    protected function getFinalSchemas(KernelInterface $kernel, BundleInterface $bundle = null)
    {
        if (null !== $bundle) {
            return $this->getSchemaLocator()->locateFromBundle($bundle);
        }

        return $this->getSchemaLocator()->locateFromBundles($kernel->getBundles());
    }

    /**
     * Run a Symfony command.
     *
     * @param Command         $command    The command to run.
     * @param array           $parameters An array of parameters to give to the command.
     * @param InputInterface  $input      An InputInterface instance
     * @param OutputInterface $output     An OutputInterface instance
     *
     * @return int The command return code.
     */
    protected function runCommand(Command $command, array $parameters, InputInterface $input, OutputInterface $output)
    {
        // add the command's name to the parameters
        array_unshift($parameters, $this->getName());

        // merge the default parameters
        $parameters = array_merge(
            array(
                '--input-dir'   => $this->cacheDir,
                '--output-dir'  => $this->cacheDir,
                '--verbose'     => $input->getOption('verbose'),
            ),
            $parameters
        );

        if ($input->hasOption('platform')) {
            $parameters['--platform'] = $input->getOption('platform');
        }

        $command->setApplication($this->getApplication());

        // and run the sub-command
        return $command->run(new ArrayInput($parameters), $output);
    }

    /*
     * Create an XML file which represents propel.configuration
     *
     * @param string $file Should be 'buildtime-conf.xml'.
     */
    protected function createBuildTimeFile($file)
    {
        $xml = strtr(<<<EOT
<?xml version="1.0"?>
<config>
<propel>
<datasources default="%default_connection%">

EOT
        , array('%default_connection%' => $this->getContainer()->getParameter('propel.dbal.default_connection')));

        $datasources = $this->getContainer()->getParameter('propel.configuration');
        foreach ($datasources as $name => $datasource) {
            $xml .= strtr(<<<EOT
<datasource id="%name%">
<adapter>%adapter%</adapter>
<connection>
<dsn>%dsn%</dsn>
<user>%username%</user>
<password>%password%</password>
</connection>
</datasource>

EOT
            , array(
                '%name%' => $name,
                '%adapter%' => $datasource['adapter'],
                '%dsn%' => $datasource['connection']['dsn'],
                '%username%' => $datasource['connection']['user'],
                '%password%' => isset($datasource['connection']['password']) ? $datasource['connection']['password'] : '',
            ));
        }

        $xml .= <<<EOT
</datasources>
</propel>
</config>
EOT;

        file_put_contents($file, $xml);
    }

    /**
     * Translates a list of connection names to their DSN equivalents.
     *
     * @param array $connections The names.
     *
     * @return array
     */
    protected function getConnections(array $connections)
    {
        $dsnList = array();
        foreach ($connections as $connection) {
            $dsnList[] = sprintf('%s=%s', $connection, $this->getDsn($connection));
        }

        return $dsnList;
    }

    /**
     * Get the data (host, user, ...) for a given connection.
     *
     * @param string $name The connection name.
     *
     * @return array The connection data.
     */
    protected function getConnectionData($name)
    {
        $knownConnections = $this->getContainer()->getParameter('propel.configuration');
        if (!isset($knownConnections[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown connection "%s"', $name));
        }

        return $knownConnections[$name];
    }

    /**
     * Get the DSN for a given connection.
     *
     * @param string $connectionName The connection name.
     *
     * @return string The DSN.
     */
    protected function getDsn($connectionName)
    {
        $connection = $this->getConnectionData($connectionName);
        $connectionData = $connection['connection'];

        return sprintf('%s;user=%s;password=%s', $connectionData['dsn'], $connectionData['user'], $connectionData['password']);
    }

    /**
     * Create a 'build.properties' file.
     *
     * @param KernelInterface $kernel The application kernel.
     * @param string          $file   Should be 'build.properties'.
     */
    protected function createBuildPropertiesFile(KernelInterface $kernel, $file)
    {
        $fs = new Filesystem();

        $buildProperties = $this->getContainer()->getParameter('propel.build_properties');
        $iniFile = $kernel->getRootDir().'/config/propel.ini';

        if ($fs->exists($iniFile)) {
            $buildProperties = array_merge(
                parse_ini_file($iniFile),
                $buildProperties
            );
        }

        $fs->dumpFile($file, $this->arrayToIni($buildProperties));
    }

    /**
     * @return \Symfony\Component\Config\FileLocatorInterface
     */
    protected function getSchemaLocator()
    {
        return $this->getContainer()->get('propel.schema_locator');
    }

    /**
     * Check the configuration parameters.
     *
     * @throws RuntimeException If the configuration is invalid.
     */
    protected function checkConfiguration()
    {
        $parameters = $this->getContainer()->getParameter('propel.configuration');

        if (0 === count($parameters)) {
            throw new \RuntimeException('Propel should be configured (no database configuration found).');
        }
    }

    /**
     * Return the package prefix for a given bundle.
     *
     * @param Bundle $bundle
     * @param string $baseDirectory The base directory to exclude from prefix.
     *
     * @return string
     */
    protected function getPackagePrefix(Bundle $bundle, $baseDirectory = '')
    {
        $parts  = explode(DIRECTORY_SEPARATOR, realpath($bundle->getPath()));
        $length = count(explode('\\', $bundle->getNamespace())) * (-1);

        $prefix = implode(DIRECTORY_SEPARATOR, array_slice($parts, 0, $length));
        $prefix = ltrim(str_replace($baseDirectory, '', $prefix), DIRECTORY_SEPARATOR);

        if (!empty($prefix)) {
            $prefix = str_replace(DIRECTORY_SEPARATOR, '.', $prefix).'.';
        }

        return $prefix;
    }

    /**
     * Return the current Propel cache directory.
     *
     * @return string The current Propel cache directory.
     */
    protected function getCacheDir()
    {
        return $this->cacheDir;
    }

    /**
     * Converts an array to its INI equivalent
     *
     * @param array $data The array to convert.
     *
     * @return string The INI formatted array.
     */
    protected function arrayToIni(array $data)
    {
        $lines = array();

        foreach ($data as $key => $value) {
            $lines[] = $key . ' =' . (!empty($value) ? ' ' . $value : '');
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Extract the database name from a given DSN
     *
     * @param  string $dsn A DSN
     * @return string The database name extracted from the given DSN
     */
    protected function parseDbName($dsn)
    {
        preg_match('#dbname=([a-zA-Z0-9\_]+)#', $dsn, $matches);

        if (isset($matches[1])) {
            return $matches[1];
        }

        // e.g. SQLite
        return null;
    }
}
