<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
    0 =>
        array (
            'name' => 'CRM_Reports_Form_Report_ContributeSummary',
            'entity' => 'ReportTemplate',
            'params' =>
                array (
                    'version' => 3,
                    'label' => 'Contribution Summary (MAF version)',
                    'description' => 'Contribution summary with extended bar chart functionality',
                    'class_name' => 'CRM_Reports_Form_Report_ContributeSummary',
                    'report_url' => 'no.maf.reports/contribution_summary',
                    'component' => 'CiviContribute',
                ),
        ),
);