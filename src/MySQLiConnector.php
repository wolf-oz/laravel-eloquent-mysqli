<?php

namespace LaravelEloquentMySQLi;

use Exception;
use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Arr;
use mysqli;

class MySQLiConnector extends Connector implements ConnectorInterface
{
    /**
     * @var array
     */
    protected $options = [];

    /**
     * Establish a database connection.
     *
     * @param  array $config
     * @return \mysqli
     */
    public function connect(array $config)
    {
        $dsn = $this->getDsn($config);

        $options = $this->getOptions($config);

        // Inject the connection in directly if possible
        if (isset($config['connection']) && is_a($config['connection'], mysqli::class)) {
            $connection = $config['connection'];
        } else {
            // We need to grab the PDO options that should be used while making the brand
            // new connection instance. The PDO options control various aspects of the
            // connection's behavior, and some might be specified by the developers.
            $connection = $this->createConnection($dsn, $config, $options);
        }

        if (!empty($config['database'])) {
            $connection->query("use `{$config['database']}`;");
        }

        $this->configureEncoding($connection, $config);

        // Next, we will check to see if a timezone has been specified in this config
        // and if it has we will issue a statement to modify the timezone with the
        // database. Setting this DB timezone is an optional configuration item.
        $this->configureTimezone($connection, $config);

        $this->setModes($connection, $config);

        return $connection;
    }

    /**
     * Create a new PDO connection.
     *
     * @param  string $dsn
     * @param  array $config
     * @param  array $options
     * @return \mysqli
     */
    public function createConnection($dsn, array $config, array $options)
    {
        list($username, $password, $host, $port, $database) = [
            Arr::get($config, 'username'),
            Arr::get($config, 'password'),
            Arr::get($config, 'host', 'localhost'),
            Arr::get($config, 'port', 3306),
            Arr::get($config, 'database'),
        ];

        try {
            return $this->createMySqliConnection(
                $host, $username, $password, (int)$port, $database, $options
            );
        } catch (Exception $e) {
            return $this->tryAgainIfCausedByLostMySqliConnection(
                $e, $host, $username, $password, (int)$port, $database, $options
            );
        }
    }

    /**
     * Handle an exception that occurred during connect execution.
     *
     * @param  \Exception $e
     * @param  string $host
     * @param  string $username
     * @param  string $password
     * @param  int $port
     * @param  string $database
     * @param  array $options
     * @return \mysqli
     *
     * @throws \Exception
     */
    protected function tryAgainIfCausedByLostMySqliConnection(Exception $e, $host, $username, $password, $port, $database, $options)
    {
        if ($this->causedByLostConnection($e)) {
            return $this->createMySqliConnection($host, $username, $password, $port, $database, $options);
        }

        throw $e;
    }

    /**
     * Create a new PDO connection instance.
     *
     * @param  string $host
     * @param  string $username
     * @param  string $password
     * @param  int $port
     * @param  string $database
     * @param  array $options
     * @return \mysqli
     */
    protected function createMySqliConnection($host, $username, $password, $port, $database, $options)
    {
        $connection = new mysqli($host, $username, $password, $database, $port);

        foreach ($options as $name => $value) {
            $connection->options($name, $value);
        }

        return $connection;
    }

    /**
     * Set the connection character set and collation.
     *
     * @param  \mysqli $connection
     * @param  array $config
     * @return void
     */
    protected function configureEncoding($connection, array $config)
    {
        if (!isset($config['charset'])) {
            return $connection;
        }

        $connection->prepare(
            "set names '{$config['charset']}'" . $this->getCollation($config)
        )->execute();
    }

    /**
     * Get the collation for the configuration.
     *
     * @param  array $config
     * @return string
     */
    protected function getCollation(array $config)
    {
        return !is_null($config['collation']) ? " collate '{$config['collation']}'" : '';
    }

    /**
     * Set the timezone on the connection.
     *
     * @param  \mysqli $connection
     * @param  array $config
     * @return void
     */
    protected function configureTimezone($connection, array $config)
    {
        if (isset($config['timezone'])) {
            $connection->prepare('set time_zone="' . $config['timezone'] . '"')->execute();
        }
    }

    /**
     * Create a DSN string from a configuration.
     *
     * Chooses socket or host/port based on the 'unix_socket' config value.
     *
     * @param  array $config
     * @return string
     */
    protected function getDsn(array $config)
    {
        return $this->hasSocket($config)
            ? $this->getSocketDsn($config)
            : $this->getHostDsn($config);
    }

    /**
     * Determine if the given configuration array has a UNIX socket value.
     *
     * @param  array $config
     * @return bool
     */
    protected function hasSocket(array $config)
    {
        return isset($config['unix_socket']) && !empty($config['unix_socket']);
    }

    /**
     * Get the DSN string for a socket configuration.
     *
     * @param  array $config
     * @return string
     */
    protected function getSocketDsn(array $config)
    {
        return "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']}";
    }

    /**
     * Get the DSN string for a host / port configuration.
     *
     * @param  array $config
     * @return string
     */
    protected function getHostDsn(array $config)
    {
        extract($config, EXTR_SKIP);

        return isset($port)
            ? "mysql:host={$host};port={$port};dbname={$database}"
            : "mysql:host={$host};dbname={$database}";
    }

    /**
     * Set the modes for the connection.
     *
     * @param  \mysqli $connection
     * @param  array $config
     * @return void
     */
    protected function setModes(mysqli $connection, array $config)
    {
        if (isset($config['modes'])) {
            $this->setCustomModes($connection, $config);
        } elseif (isset($config['strict'])) {
            if ($config['strict']) {
                $connection->prepare($this->strictMode())->execute();
            } else {
                $connection->prepare("set session sql_mode='NO_ENGINE_SUBSTITUTION'")->execute();
            }
        }
    }

    /**
     * Set the custom modes on the connection.
     *
     * @param  \mysqli $connection
     * @param  array $config
     * @return void
     */
    protected function setCustomModes(mysqli $connection, array $config)
    {
        $modes = implode(',', $config['modes']);

        $connection->prepare("set session sql_mode='{$modes}'")->execute();
    }

    /**
     * Get the query to enable strict mode.
     *
     * @return string
     */
    protected function strictMode()
    {
        return "set session sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'";
    }
}
