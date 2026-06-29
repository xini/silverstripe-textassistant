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
        'Approvers' => Member::class,
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->removeByName([
            'Approvers',
        ]);
        $fields->addFieldsToTab(
            'Root.Main',
            [
                CheckboxSetField::create(
                    'Approvers',
                    _t(__CLASS__ . '.APPROVERS', 'AI Translation Approvers'),
                    Permission::get_members_by_permission([
                        "ADMIN",
                        "CMS_ACCESS_TranslationAdmin"
                    ])
                )
            ]
        );
    }
}
