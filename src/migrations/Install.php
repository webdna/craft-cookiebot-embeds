<?php

namespace webdna\cookiebotembeds\migrations;

use craft\db\Migration;
use craft\db\Table;
use webdna\cookiebotembeds\records\Sighting;

/**
 * Creates the report table (spec section 4) on install and drops it on uninstall. Rows go with
 * their site through the cascading foreign key.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $table = Sighting::TABLE;

        if ($this->db->tableExists($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'host' => $this->string(Sighting::HOST_LENGTH)->notNull()->defaultValue(''),
            'vendor' => $this->string(Sighting::VENDOR_LENGTH)->null(),
            'category' => $this->string(Sighting::CATEGORY_LENGTH)->notNull(),
            'action' => $this->string(Sighting::ACTION_LENGTH)->notNull(),
            'kind' => $this->string(Sighting::KIND_LENGTH)->notNull(),
            'examplePath' => $this->string(Sighting::PATH_LENGTH)->notNull()->defaultValue(''),
            'hits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, Sighting::UNIQUE_KEY, true);
        // Garbage collection prunes by last seen (BR-26).
        $this->createIndex(null, $table, ['dateUpdated']);
        $this->addForeignKey(null, $table, ['siteId'], Table::SITES, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Sighting::TABLE);

        return true;
    }
}
