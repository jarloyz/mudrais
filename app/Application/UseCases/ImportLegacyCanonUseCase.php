<?php

namespace App\Application\UseCases;

use App\Application\Contracts\LegacyCanonImporter;
use App\Application\Contracts\StructuredLogger;
use InvalidArgumentException;

final readonly class ImportLegacyCanonUseCase
{
    public function __construct(
        private LegacyCanonImporter $importer,
        private StructuredLogger $logger,
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function execute(string $sourcePath, string $vaultId, string $scope = 'canon_base+scenes'): array
    {
        if (! is_file($sourcePath)) {
            throw new InvalidArgumentException("Legacy SQLite no encontrado: {$sourcePath}");
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'import_legacy_canon',
            'sourcePath' => $sourcePath,
            'vaultId' => $vaultId,
            'scope' => $scope,
        ]);

        $logger->info('Inicio de importacion de canon legacy');

        try {
            $result = $this->importer->import($sourcePath, $vaultId, $scope);
            $logger->info('Importacion de canon legacy completada', $result);

            return $result;
        } catch (\Throwable $exception) {
            $logger->error('Fallo importacion de canon legacy', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
