<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;

/**
 * Base for tests that need a REAL pgvector column / hnsw index (the `vector`
 * type and KNN search). The default test connection is sqlite, which has no
 * vector type; these tests instead run against an isolated `pgvector`
 * connection (config/database.php) pointed at an ephemeral pgvector container
 * via PGVECTOR_TEST_* env. When that container isn't reachable the whole case
 * skips loudly rather than redding the suite — provider-mocked logic tests that
 * don't need the vector type stay on sqlite (TestCase).
 */
abstract class PgvectorTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! $this->pgvectorReachable()) {
            $this->markTestSkipped(
                'pgvector test connection not reachable (set PGVECTOR_TEST_HOST/PORT/DB/USER/PASSWORD '
                .'to an ephemeral pgvector container). The vector-type tests need real pgvector.'
            );
        }

        parent::setUp();
    }

    /**
     * Point the whole app at the pgvector connection at boot — BEFORE
     * RefreshDatabase migrates — so the embeddings table is created with a real
     * vector(1536) column.
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'pgvector');
        $app['db']->setDefaultConnection('pgvector');

        return $app;
    }

    /** The Eloquent connection name the embeddings live on in these tests. */
    protected function embeddingConnection(): string
    {
        return 'pgvector';
    }

    private function pgvectorReachable(): bool
    {
        $host = env('PGVECTOR_TEST_HOST', '127.0.0.1');
        $port = env('PGVECTOR_TEST_PORT', '5432');
        $db = env('PGVECTOR_TEST_DB', 'testing');
        $user = env('PGVECTOR_TEST_USER', 'postgres');
        $pass = env('PGVECTOR_TEST_PASSWORD', '');

        try {
            new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
                PDO::ATTR_TIMEOUT => 3,
            ]);

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
