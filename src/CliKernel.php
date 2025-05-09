<?php

use MonkeysLegion\Cli\Command\DatabaseMigrationCommand;
use MonkeysLegion\Cli\Command\MigrateCommand;
use MonkeysLegion\Cli\Command\RollbackCommand;

$this->addCommands([
    DatabaseMigrationCommand::class,
    MigrateCommand::class,
    RollbackCommand::class,
]);