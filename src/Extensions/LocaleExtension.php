<?php

namespace S2Hub\TextAssistant\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class LocaleExtension extends Extension
{
    private static $many_many = [
        'Proofreaders' => Member::class,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName([
            'Proofreaders',
        ]);
        $fields->addFieldsToTab(
            'Root.Main',
            [
                CheckboxSetField::create(
                    'Proofreaders',
                    _t(__CLASS__ . '.PROOFREADERS', 'AI Translation Proofreaders'),
                    Permission::get_members_by_permission([
                        "ADMIN",
                        "CMS_ACCESS_TranslationAdmin"
                    ])
                )
            ]
        );
    }
}
