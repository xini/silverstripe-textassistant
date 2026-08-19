<?php

namespace S2Hub\TextAssistant\Models;

use Exception;
use SilverStripe\Dev\Backtrace;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLInsert;
use SilverStripe\ORM\SS_List;

class TranslationAction_ObjectQueue extends DataObject
{
    private static $table_name = "TextAssistant_TranslationAction_ObjectQueue";

    private static $db = [
        'GroupIdentifier' => 'Varchar(24)'
    ];

    private static $has_one = [
        'Object' => DataObject::class,
    ];

    private static $indexes = [
        'GroupIdentifier' => true
    ];

    private static $queued_in_group = [];
    private static $insert = null;
    private static $shutdown_function_registered = false;

    public static function queue($itemOrList, string $group, string $optionalClassName = "")
    {

        if ($itemOrList instanceof DataObject) {
            $ids = [$itemOrList->ID];
            $className = get_class($itemOrList);
            
        } else if ($itemOrList instanceof SS_List) {
            $ids = $itemOrList->column('ID');
            $className = $itemOrList->dataClass();

        } else if (is_array($itemOrList)) {
            $ids = $itemOrList;
            $className = $optionalClassName;

        } else {
            
            throw new Exception("Type not supported, " . get_class($itemOrList) . " " . Backtrace::backtrace(true));
        }

        if (self::$insert === null) {
            self::$insert = new SQLInsert(DataObject::getSchema()->tableName(self::class));
        }

        foreach ($ids as $id) {
            if (isset(self::$queued_in_group[$group][$className][$id])) continue;
            self::$queued_in_group[$group][$className][$id] = true;

            self::$insert->addRow([
                'ObjectID' => $id,
                'ObjectClass' => $className,
                'GroupIdentifier' => $group,
                'Created' => date("Y-m-d H:i:s"),
                'LastEdited' => date("Y-m-d H:i:s"),
            ]);

        }

        if (sizeof(self::$insert->getRows()) >= 500) {
            self::$insert->execute();
            self::$insert->clear();
        }

    }

    /**
     * Inserts the remaining IDs into database, must be called
     */
    public static function insertRemains()
    {
        if (self::$insert !== null && sizeof(self::$insert->getRows())) {
            self::$insert->execute();
            self::$insert->clear();
        }
    }

     /**
     * Flush the in-memory queue and release the per-request de-duplication
     * state. This is important for long-running queued-job workers.
     */
    public static function resetQueueState()
    {
        self::insertRemains();

        self::$queued_in_group = [];
        self::$insert = null;
    }

    public static function objectQueuedInGroup(DataObject $object, string $group)
    {
        return isset(self::$queued_in_group[$group][get_class($object)][$object->ID]);
    }
}