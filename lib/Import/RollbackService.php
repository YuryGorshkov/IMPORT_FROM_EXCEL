<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Import;

use Bitrix\Main\Type\DateTime;
use WebEnot\ImportExcel\Orm\ChangeTable;
use WebEnot\ImportExcel\Orm\JobTable;
use WebEnot\ImportExcel\Support\Json;
use WebEnot\ImportExcel\Target\IblockGatewayInterface;

final class RollbackService
{
    public function __construct(private readonly IblockGatewayInterface $gateway)
    {
    }

    public function rollback(int $jobId): int
    {
        $restored = 0;
        $changes = ChangeTable::getList([
            'filter' => ['=JOB_ID' => $jobId, '=ROLLED_BACK' => 'N'],
            'order' => ['ID' => 'DESC'],
        ]);
        while ($change = $changes->fetch()) {
            $after = Json::decode((string) $change['AFTER_DATA']);
            if ($change['ACTION'] === 'added') {
                $this->gateway->delete((int) $change['ENTITY_ID']);
            } elseif ($change['ACTION'] === 'updated') {
                $this->gateway->restore((int) $change['ENTITY_ID'], Json::decode((string) $change['BEFORE_DATA']));
            }
            $this->gateway->deleteSectionsIfEmpty((array) ($after['created_section_ids'] ?? []));
            ChangeTable::update((int) $change['ID'], ['ROLLED_BACK' => 'Y']);
            $restored++;
        }
        JobTable::update($jobId, [
            'STATUS' => JobTable::STATUS_ROLLED_BACK,
            'FINISHED_AT' => new DateTime(),
        ]);
        return $restored;
    }
}
