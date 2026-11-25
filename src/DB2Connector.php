<?php

declare(strict_types=1);

namespace Beryildiz\DB2Driver;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;

class DB2Connector extends Connector implements ConnectorInterface
{
    public function connect(array $config): \PDO
    {
        $connection = $this->createConnection(
            $this->getDsn($config),
            $config,
            $this->getOptions($config)
        );

        if (isset($config['schema']) && $config['schema'] !== '') {
            $schema = $this->normalizeSchema($config['schema']);
            $connection->exec("SET SCHEMA {$schema}");
        }

        return $connection;
    }

    /**
     * Normalisiert und validiert einen Schema-Namen.
     *
     * Gibt den Namen in Großbuchstaben zurück. Wirft eine InvalidArgumentException,
     * wenn der Name nicht identifier-sicher ist (Injection-Schutz — kein Binding,
     * weil DB2 for i SET SCHEMA ? je nach Treiberversion ablehnt, identisch zu V7).
     * Erlaubt: Buchstaben, Ziffern, Unterstrich, $, @, #, Punkt.
     */
    protected function normalizeSchema(string $schema): string
    {
        $schema = strtoupper($schema);

        if (! preg_match('/^[A-Z0-9_$@#.]+$/', $schema)) {
            throw new \InvalidArgumentException(
                "DB2Connector: Ungültiger Schema-Name \"{$schema}\". Nur A-Z, 0-9, _, \$, @, # und . erlaubt."
            );
        }

        return $schema;
    }

    /**
     * Return properly formatted dsn from config
     */
    public function getDsn(array $config): string
    {
        // Base DSN
        $dsnParts = ['odbc:DRIVER='.$config['driverName'], 'System='.$this->formatDsnValue($config['host'])];

        // "Port" ist kein Keyword des IBM i Access ODBC-Treibers und wird von ihm ignoriert —
        // nur der Abwärtskompatibilität halber angehängt, wenn konfiguriert.
        if (($config['port'] ?? null) !== null && $config['port'] !== '') {
            $dsnParts[] = 'Port='.$this->formatDsnValue($config['port']);
        }

        $dsnParts[] = 'Database='.$this->formatDsnValue($config['database']);
        $dsnParts[] = 'UserID='.$this->formatDsnValue($config['username']);
        $dsnParts[] = 'Password='.$this->formatDsnValue($config['password']);

        // Include ODBC Keywords if present
        if (array_key_exists('odbc_keywords', $config)) {
            $keywords = [];

            foreach ($config['odbc_keywords'] as $key => $value) {
                $keywords[] = $key.'='.$this->formatDsnValue($value);
            }

            $dsnParts = array_merge($dsnParts, $keywords);
        }

        return implode(';', $dsnParts);
    }

    /**
     * Maskiert einen DSN-Wert nach ODBC-Konvention.
     *
     * Werte mit ";", "{", "}" oder Leerzeichen am Rand würden den Connection-String
     * zerlegen (z. B. ein Passwort mit Semikolon) und werden daher in {…} gesetzt,
     * enthaltene "}" verdoppelt. Bereits vollständig geklammerte Werte (z. B.
     * "{IBM i Access ODBC Driver}") bleiben unverändert, ebenso alle Werte ohne
     * Sonderzeichen — der DSN ist für sie byte-identisch zu früheren Versionen.
     */
    protected function formatDsnValue(mixed $value): string
    {
        $value = (string) $value;

        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            return $value;
        }

        if (strpbrk($value, ';{}') !== false || $value !== trim($value)) {
            return '{'.str_replace('}', '}}', $value).'}';
        }

        return $value;
    }
}
