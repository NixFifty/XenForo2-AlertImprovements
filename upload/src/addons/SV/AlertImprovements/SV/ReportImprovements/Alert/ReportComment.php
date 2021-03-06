<?php

namespace SV\AlertImprovements\SV\ReportImprovements\Alert;

use SV\AlertImprovements\ISummarizeAlert;
use SV\AlertImprovements\XF\Alert\SummarizeAlertTrait;

/**
 * Extends \SV\ReportImprovements\Alert\ReportComment
 */
class ReportComment extends XFCP_ReportComment implements ISummarizeAlert
{
    use SummarizeAlertTrait;

    /**
     * @param array $optOuts
     * @return bool
     */
    public function canSummarizeForUser(array $optOuts): bool
    {
        return empty($optOuts['report_comment_react']);
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public function consolidateAlert(string &$contentType, int &$contentId, array $item): bool
    {
        switch ($contentType)
        {
            case 'report_comment':
                return true;
            default:
                return false;
        }
    }
}