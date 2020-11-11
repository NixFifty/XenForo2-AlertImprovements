<?php

namespace SV\AlertImprovements\XF\Repository;

use SV\AlertImprovements\Globals;
use SV\AlertImprovements\ISummarizeAlert;
use XF\Db\AbstractAdapter;
use XF\Db\DeadlockException;
use SV\AlertImprovements\XF\Entity\UserAlert as Alerts;
use XF\Entity\User;
use XF\Entity\UserAlert as UserAlertEntity;
use XF\Mvc\Entity\Finder;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Repository
 */
class UserAlert extends XFCP_UserAlert
{
    public function summarizeAlertsForUser(User $user)
    {
        // post rating summary alerts really can't me merged, so wipe all summary alerts, and then try again
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user) {

            $db->query("
                DELETE FROM xf_user_alert
                WHERE alerted_user_id = ? AND summerize_id IS NULL AND `action` LIKE '%_summary'
            ", $user->user_id);

            $db->query('
                UPDATE xf_user_alert
                SET summerize_id = NULL
                WHERE alerted_user_id = ? AND summerize_id IS NOT NULL
            ', $user->user_id);

            $this->updateUnviewedCountForUser($user);
            $this->updateUnreadCountForUser($user);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);

        // do summerization outside the above transaction
        $this->checkSummarizeAlertsForUser($user->user_id, true, true, \XF::$time);
    }

    /**
     * @param User $user
     * @param int  $alertId
     * @return \SV\AlertImprovements\XF\Finder\UserAlert|Finder
     */
    public function findAlertForUser(User $user, int $alertId)
    {
        return $this->finder('XF:UserAlert')
                    ->where(['alert_id', $alertId])
                    ->where(['alerted_user_id', $user->user_id]);
    }

    /**
     * @param int      $userId
     * @param null|int $cutOff
     * @return Finder
     * @noinspection PhpMissingParamTypeInspection
     */
    public function findAlertsForUser($userId, $cutOff = null)
    {
        /** @var \SV\AlertImprovements\XF\Finder\UserAlert $finder */
        $finder = parent::findAlertsForUser($userId, $cutOff);
        if (!Globals::$skipSummarizeFilter)
        {
            $finder->where(['summerize_id', null]);
        }

        if (Globals::$showUnreadOnly)
        {
            $finder->whereOr([
                ['read_date', '=', 0],
                ['view_date', '=', 0]
            ]);
        }

        if (Globals::$skipSummarize)
        {
            return $finder;
        }

        $finder->shimSource(
            function ($limit, $offset) use ($userId, $finder) {
                if ($offset !== 0)
                {
                    return null;
                }
                $alerts = $this->checkSummarizeAlertsForUser($userId);

                if ($alerts === null)
                {
                    return null;
                }

                $alerts = array_slice($alerts, 0, $limit, true);

                return $finder->materializeAlerts($alerts);
            }
        );

        return $finder;
    }

    /**
     * @param int  $userId
     * @param bool $force
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return array|null
     * @throws \Exception
     */
    protected function checkSummarizeAlertsForUser(int $userId, $force = false, $ignoreReadState = false, $summaryAlertViewDate = 0)
    {
        if ($userId !== \XF::visitor()->user_id)
        {
            /** @var User $user */
            $user = $this->finder('XF:User')
                         ->where('user_id', $userId)
                         ->fetchOne();

            return \XF::asVisitor(
                $user,
                function () use ($force, $ignoreReadState, $summaryAlertViewDate) {
                    return $this->checkSummarizeAlerts($force, $ignoreReadState, $summaryAlertViewDate);
                }
            );
        }

        return $this->checkSummarizeAlerts($force, $ignoreReadState, $summaryAlertViewDate);
    }

    /**
     * @param bool $force
     * @param bool $ignoreReadState
     * @param int  $summaryAlertViewDate
     * @return null|array
     */
    protected function checkSummarizeAlerts(bool $force = false, bool $ignoreReadState = false, int $summaryAlertViewDate = 0)
    {
        if ($force || $this->canSummarizeAlerts())
        {
            return $this->summarizeAlerts($ignoreReadState, $summaryAlertViewDate);
        }

        return null;
    }

    public function insertUnsummarizedAlerts(User $user, int $summaryId)
    {
        $this->db()->executeTransaction(function (AbstractAdapter $db) use ($user, $summaryId) {
            $userId = $user->user_id;
            // Delete summary alert
            /** @var Alerts $summaryAlert */
            $summaryAlert = $this->finder('XF:UserAlert')
                                 ->where('alert_id', $summaryId)
                                 ->fetchOne();
            if (!$summaryAlert)
            {
                return;
            }
            $summaryAlert->delete(false, false);

            // Make alerts visible
            $stmt = $db->query('
                UPDATE xf_user_alert
                SET summerize_id = NULL, view_date = 0, read_date = 0
                WHERE alerted_user_id = ? AND summerize_id = ?
            ', [$userId, $summaryId]);

            // Reset unread alerts counter
            $increment = $stmt->rowsAffected();
            $db->query('
                UPDATE xf_user
                SET alerts_unread = LEAST(alerts_unread + ?, 65535),
                    alerts_unviewed = LEAST(alerts_unviewed + ?, 65535)
                WHERE user_id = ?
            ', [$increment, $userId]);

            $user->setAsSaved('alerts_unread', $user->alerts_unread + $increment);
            $user->setAsSaved('alerts_unread', $user->alerts_unviewed + $increment);
        });
    }

    protected function canSummarizeAlerts(): bool
    {
        if (Globals::$skipSummarize)
        {
            return false;
        }

        if (empty(\XF::options()->sv_alerts_summerize))
        {
            return false;
        }

        $visitor = \XF::visitor();
        /** @var \SV\AlertImprovements\XF\Entity\UserOption $option */
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;
        $summarizeUnreadThreshold = $summarizeThreshold * 2 > 25 ? 25 : $summarizeThreshold * 2;

        return $visitor->alerts_unviewed > $summarizeUnreadThreshold;
    }

    public function summarizeAlerts(bool $ignoreReadState, int $summaryAlertViewDate): array
    {
        // TODO : finish summarizing alerts
        $visitor = \XF::visitor();
        $userId = $visitor->user_id;
        /** @var \SV\AlertImprovements\XF\Entity\UserOption $option */
        $option = $visitor->Option;
        $summarizeThreshold = $option->sv_alerts_summarize_threshold;

        /** @var \SV\AlertImprovements\XF\Finder\UserAlert $finder */
        $finder = $this->finder('XF:UserAlert')
                       ->where('alerted_user_id', $userId)
                       ->order('event_date', 'desc');

        $finder->where('summerize_id', null);

        /** @var array $alerts */
        $alerts = $finder->fetchRaw();

        $outputAlerts = [];

        // build the list of handlers at once, and exclude based
        $handlers = $this->getAlertHandlersForConsolidation();
        // nothing to be done
        $userHandler = empty($handlers['user']) ? null : $handlers['user'];
        if (empty($handlers) || ($userHandler && count($handlers) == 1))
        {
            return $alerts;
        }

        // collect alerts into groupings by content/id
        $groupedContentAlerts = [];
        $groupedUserAlerts = [];
        $groupedAlerts = false;
        foreach ($alerts AS $id => $item)
        {
            if ((!$ignoreReadState && $item['view_date']) ||
                empty($handlers[$item['content_type']]) ||
                (bool)preg_match('/^.*_summary$/', $item['action']))
            {
                $outputAlerts[$id] = $item;
                continue;
            }
            $handler = $handlers[$item['content_type']];
            if (!$handler->canSummarizeItem($item))
            {
                $outputAlerts[$id] = $item;
                continue;
            }

            $contentType = $item['content_type'];
            $contentId = $item['content_id'];
            $contentUserId = $item['user_id'];
            if ($handler->consolidateAlert($contentType, $contentId, $item))
            {
                $groupedContentAlerts[$contentType][$contentId][$id] = $item;

                if ($userHandler && $userHandler->canSummarizeItem($item))
                {
                    if (!isset($groupedUserAlerts[$contentUserId]))
                    {
                        $groupedUserAlerts[$contentUserId] = ['c' => 0, 'd' => []];
                    }
                    $groupedUserAlerts[$contentUserId]['c'] += 1;
                    $groupedUserAlerts[$contentUserId]['d'][$contentType][$contentId][$id] = $item;
                }
            }
            else
            {
                $outputAlerts[$id] = $item;
            }
        }

        // determine what can be summerised by content types. These require explicit support (ie a template)
        $grouped = 0;
        foreach ($groupedContentAlerts AS $contentType => &$contentIds)
        {
            $handler = $handlers[$contentType];
            foreach ($contentIds AS $contentId => $alertGrouping)
            {
                if ($this->insertSummaryAlert(
                    $handler, $summarizeThreshold, $contentType, $contentId, $alertGrouping, $grouped, $outputAlerts,
                    'content', 0, $summaryAlertViewDate
                ))
                {
                    unset($contentIds[$contentId]);
                    $groupedAlerts = true;
                }
            }
        }

        // see if we can group some alert by user (requires deap knowledge of most content types and the template)
        if ($userHandler)
        {
            foreach ($groupedUserAlerts AS $senderUserId => &$perUserAlerts)
            {
                if (!$summarizeThreshold || $perUserAlerts['c'] < $summarizeThreshold)
                {
                    unset($groupedUserAlerts[$senderUserId]);
                    continue;
                }

                $userAlertGrouping = [];
                foreach ($perUserAlerts['d'] AS $contentType => &$contentIds)
                {
                    foreach ($contentIds AS $contentId => $alertGrouping)
                    {
                        foreach ($alertGrouping AS $id => $alert)
                        {
                            if (isset($groupedContentAlerts[$contentType][$contentId][$id]))
                            {
                                $alert['content_type_map'] = $contentType;
                                $alert['content_id_map'] = $contentId;
                                $userAlertGrouping[$id] = $alert;
                            }
                        }
                    }
                }
                if ($userAlertGrouping && $this->insertSummaryAlert(
                        $userHandler, $summarizeThreshold, 'user', $userId, $userAlertGrouping, $grouped, $outputAlerts,
                        'user', $senderUserId, $summaryAlertViewDate
                    ))
                {
                    foreach ($userAlertGrouping AS $id => $alert)
                    {
                        unset($groupedContentAlerts[$alert['content_type_map']][$alert['content_id_map']][$id]);
                    }
                    $groupedAlerts = true;
                }
            }
        }

        // output ungrouped alerts
        foreach ($groupedContentAlerts AS $contentType => $contentIds)
        {
            foreach ($contentIds AS $contentId => $alertGrouping)
            {
                foreach ($alertGrouping AS $alertId => $alert)
                {
                    $outputAlerts[$alertId] = $alert;
                }
            }
        }

        // update alert totals
        if ($groupedAlerts)
        {
            $this->updateUnreadCountForUser($visitor);
            $this->updateUnviewedCountForUser($visitor);
        }

        uasort(
            $outputAlerts,
            function ($a, $b) {
                if ($a['event_date'] == $b['event_date'])
                {
                    return ($a['alert_id'] < $b['alert_id']) ? 1 : -1;
                }

                return ($a['event_date'] < $b['event_date']) ? 1 : -1;
            }
        );

        return $outputAlerts;
    }

    /**
     * @param ISummarizeAlert $handler
     * @param int             $summarizeThreshold
     * @param string          $contentType
     * @param int             $contentId
     * @param Alerts[]        $alertGrouping
     * @param int             $grouped
     * @param Alerts[]        $outputAlerts
     * @param string          $groupingStyle
     * @param int             $senderUserId
     * @param int             $summaryAlertViewDate
     * @return bool
     */
    protected function insertSummaryAlert(ISummarizeAlert $handler, int $summarizeThreshold, string $contentType, int $contentId, array $alertGrouping, int &$grouped, array &$outputAlerts, string $groupingStyle, int $senderUserId, int $summaryAlertViewDate) : bool
    {
        $grouped = 0;
        if (!$summarizeThreshold || count($alertGrouping) < $summarizeThreshold)
        {
            return false;
        }
        $lastAlert = reset($alertGrouping);

        // inject a grouped alert with the same content type/id, but with a different action
        $summaryAlert = [
            'depends_on_addon_id' => 'SV/AlertImprovements',
            'alerted_user_id'     => $lastAlert['alerted_user_id'],
            'user_id'             => $senderUserId,
            'username'            => $senderUserId ? $lastAlert['username'] : 'Guest',
            'content_type'        => $contentType,
            'content_id'          => $contentId,
            'action'              => $lastAlert['action'] . '_summary',
            'event_date'          => $lastAlert['event_date'],
            'view_date'           => $summaryAlertViewDate,
            'read_date'           => $summaryAlertViewDate,
            'extra_data'          => [],
        ];
        $contentTypes = [];

        if ($lastAlert['action'] === 'rating')
        {
            foreach ($alertGrouping AS $alert)
            {
                if (!empty($alert['extra_data']) && $alert['action'] === $lastAlert['action'])
                {
                    if (!isset($contentTypes[$alert['content_type']]))
                    {
                        $contentTypes[$alert['content_type']] = 0;
                    }
                    $contentTypes[$alert['content_type']]++;

                    $extraData = @\json_decode($alert['extra_data'], true);

                    if (is_array($extraData))
                    {
                        foreach ($extraData AS $extraDataKey => $extraDataValue)
                        {
                            if (empty($summaryAlert['extra_data'][$extraDataKey][$extraDataValue]))
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue] = 1;
                            }
                            else
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue]++;
                            }
                        }
                    }
                }
            }

            if (isset($summaryAlert['extra_data']['reaction_id']))
            {
                $reactionId = $summaryAlert['extra_data']['reaction_id'];
                if (is_array($reactionId) && count($reactionId) === 1)
                {
                    $likeRatingId = (int)$this->app()->options()->svContentRatingsLikeRatingType;

                    if ($likeRatingId && !empty($reactionId[$likeRatingId]))
                    {
                        $summaryAlert['extra_data']['likes'] = $reactionId[$likeRatingId];
                    }
                }
            }
        }
        else if ($lastAlert['action'] === 'like')
        {
            $likesCounter = 0;
            foreach ($alertGrouping AS $alert)
            {
                if ($alert['action'] === 'like')
                {
                    if (!isset($contentTypes[$alert['content_type']]))
                    {
                        $contentTypes[$alert['content_type']] = 0;
                    }
                    $contentTypes[$alert['content_type']]++;
                    $likesCounter++;
                }
            }
            $summaryAlert['extra_data']['likes'] = $likesCounter;
        }
        else if ($lastAlert['action'] === 'reaction')
        {
            foreach ($alertGrouping AS $alert)
            {
                if (!empty($alert['extra_data']) && $alert['action'] === $lastAlert['action'])
                {
                    if (!isset($contentTypes[$alert['content_type']]))
                    {
                        $contentTypes[$alert['content_type']] = 0;
                    }
                    $contentTypes[$alert['content_type']]++;

                    $extraData = @\json_decode($alert['extra_data'], true);

                    if (is_array($extraData))
                    {
                        foreach ($extraData AS $extraDataKey => $extraDataValue)
                        {
                            if (empty($summaryAlert['extra_data'][$extraDataKey][$extraDataValue]))
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue] = 1;
                            }
                            else
                            {
                                $summaryAlert['extra_data'][$extraDataKey][$extraDataValue]++;
                            }
                        }
                    }
                }
            }
        }

        if ($contentTypes)
        {
            $summaryAlert['extra_data']['ct'] = $contentTypes;
        }

        if ($summaryAlert['extra_data'] === false)
        {
            $summaryAlert['extra_data'] = [];
        }

        $summaryAlert = $handler->summarizeAlerts($summaryAlert, $alertGrouping, $groupingStyle);
        if (empty($summaryAlert))
        {
            return false;
        }

        $summerizeId = $rowsAffected = null;
        $db = $this->db();
        $batchIds = \array_column($alertGrouping, 'alert_id');

        // depending on context; insertSummaryAlert may be called inside a transaction or not so we want to re-run deadlocks immediately if there is no transaction otherwise allow the caller to run
        $updateAlerts = function () use ($db, $batchIds, $summaryAlert, &$alert, &$rowsAffected, &$summerizeId) {
            // database update
            /** @var Alerts $alert */
            $alert = $this->em->create('XF:UserAlert');
            $alert->bulkSet($summaryAlert);
            $alert->save(true, false);
            $summerizeId = $alert->alert_id;

            // hide the non-summary alerts
            $stmt = $db->query('
                UPDATE xf_user_alert
                SET summerize_id = ?, view_date = if(view_date = 0, ?, view_date), read_date = if(read_date = 0, ?, read_date)
                WHERE alert_id IN (' . $db->quote($batchIds) . ')
            ', [$summerizeId, \XF::$time, \XF::$time]);
            $rowsAffected = $stmt->rowsAffected();

            return $rowsAffected;
        };

        if ($db->inTransaction())
        {
            $updateAlerts();
        }
        else
        {
            $db->executeTransaction($updateAlerts, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
        }

        // add to grouping
        $grouped += $rowsAffected;
        $outputAlerts[$summerizeId] = $alert->toArray();

        return true;
    }

    /**
     * @return \XF\Alert\AbstractHandler[]|ISummarizeAlert[]
     */
    public function getAlertHandlersForConsolidation()
    {
        $optOuts = \XF::visitor()->Option->alert_optout;
        $handlers = $this->getAlertHandlers();
        unset($handlers['bookmark_post_alt']);
        foreach ($handlers AS $key => $handler)
        {
            /** @var ISummarizeAlert $handler */
            if (!($handler instanceof ISummarizeAlert) || !$handler->canSummarizeForUser($optOuts))
            {
                unset($handlers[$key]);
            }
        }

        return $handlers;
    }

    /**
     * @param User $user
     * @param null|int   $viewDate
     */
    public function markUserAlertsViewed(User $user, $viewDate = null)
    {
        $this->markUserAlertsRead($user, $viewDate);
    }

    public function markUserAlertViewed(UserAlertEntity $alert, $viewDate = null)
    {
        $this->markUserAlertRead($alert, $viewDate);
    }

    /**
     * @param User $user
     * @param null|int   $readDate
     */
    public function markUserAlertsRead(User $user, $readDate = null)
    {
        if (Globals::$skipMarkAlertsRead || !$user->user_id)
        {
            return;
        }

        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        $db = $this->db();
        $db->executeTransaction(function() use ($db, $readDate, $user)
        {
            $db->update('xf_user_alert', ['view_date' => $readDate], "alerted_user_id = ? AND view_date = 0", $user->user_id);
            $db->update('xf_user_alert', ['read_date' => $readDate], "alerted_user_id = ? AND read_date = 0", $user->user_id);

            $user->alerts_unviewed = 0;
            $user->alerts_unread = 0;
            $user->save(true, false);
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    public function autoMarkUserAlertsRead(\XF\Mvc\Entity\AbstractCollection $alerts, User $user, $readDate = null)
    {
        $alerts = $alerts->filter(function(UserAlertEntity $alert)
        {
            return ($alert->isUnread() && $alert->auto_read);
        });

        $this->markSpecificUserAlertsRead($alerts, $user, $readDate);
    }

    protected function markSpecificUserAlertsRead(\XF\Mvc\Entity\AbstractCollection $alerts, User $user, int $readDate = null)
    {
        $userId = $user->user_id;
        if (!$userId || !$alerts->count())
        {
            return;
        }

        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        $unreadAlertIds = [];
        foreach ($alerts as $alert)
        {
            /** @var UserAlertEntity $alert */
            if ($alert->isUnread())
            {
                $unreadAlertIds[] = $alert->alert_id;
                $alert->setAsSaved('view_date', $readDate);
                $alert->setAsSaved('read_date', $readDate);

                // we need to treat this as unread for the current request so it can display the way we want
                $alert->setOption('force_unread_in_ui', true);
            }
        }

        if (!$unreadAlertIds)
        {
            return;
        }

        $this->markAlertIdsAsReadAndViewed($user, $unreadAlertIds, $readDate);
    }

    public function markUserAlertsReadForContent($contentType, $contentIds, $onlyActions = null, User $user = null, $viewDate = null)
    {
        if (!is_array($contentIds))
        {
            $contentIds = [$contentIds];
        }
        if ($onlyActions && !is_array($onlyActions))
        {
            $onlyActions = [$onlyActions];
        }

        $this->markAlertsReadForContentIds($contentType, $contentIds, $onlyActions, 0, $user, $viewDate);
    }

    public function markAlertIdsAsReadAndViewed(User $user, array $alertIds, int $readDate)
    {
        $userId = $user->user_id;
        $db = $this->db();
        $ids = $db->quote($alertIds);
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET view_date = ?
                WHERE view_date = 0 AND alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$readDate, $userId]
        );
        $viewRowsAffected = $stmt->rowsAffected();

        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET read_date = ?
                WHERE read_date = 0 AND alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$readDate, $userId]
        );
        $readRowsAffected = $stmt->rowsAffected();

        if (!$viewRowsAffected && !$readRowsAffected)
        {
            return;
        }

        try
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?),
                    alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $alerts_unviewed = max(0,$user->alerts_unviewed - $viewRowsAffected);
            $alerts_unread = max(0,$user->alerts_unread - $readRowsAffected);
        }
            /** @noinspection PhpRedundantCatchClauseInspection */
        catch (DeadlockException $e)
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?),
                    alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $row = $db->fetchRow('select alerts_unviewed, alerts_unread from xf_user where user_id = ?', $userId);
            if (!$row)
            {
                return;
            }
            $alerts_unviewed = $row['alerts_unviewed'];
            $alerts_unread = $row['alerts_unread'];
        }

        $user->setAsSaved('alerts_unviewed', $alerts_unviewed);
        $user->setAsSaved('alerts_unread', $alerts_unread);
    }

    public function markAlertIdsAsUnreadAndUnviewed(User $user, array $alertIds)
    {
        $userId = $user->user_id;
        $db = $this->db();
        $ids = $db->quote($alertIds);
        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET view_date = 0
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$userId]
        );
        $viewRowsAffected = $stmt->rowsAffected();

        $stmt = $db->query('
                UPDATE IGNORE xf_user_alert
                SET read_date = 0
                WHERE alerted_user_id = ? AND alert_id IN (' . $ids . ')
            ', [$userId]
        );
        $readRowsAffected = $stmt->rowsAffected();

        if (!$viewRowsAffected && !$readRowsAffected)
        {
            return;
        }

        try
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, 65535),
                    alerts_unread = LEAST(alerts_unread + ?, 65535)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $alerts_unviewed = min(65535,$user->alerts_unviewed - $viewRowsAffected);
            $alerts_unread = min(65535,$user->alerts_unread - $readRowsAffected);
        }
            /** @noinspection PhpRedundantCatchClauseInspection */
        catch (DeadlockException $e)
        {
            $db->query('
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, 65535),
                    alerts_unread = LEAST(alerts_unread + ?, 65535)
                WHERE user_id = ?
            ', [$viewRowsAffected, $readRowsAffected, $userId]
            );

            $row = $db->fetchRow('select alerts_unviewed, alerts_unread from xf_user where user_id = ?', $userId);
            if (!$row)
            {
                return;
            }
            $alerts_unviewed = $row['alerts_unviewed'];
            $alerts_unread = $row['alerts_unread'];
        }

        $user->setAsSaved('alerts_unviewed', $alerts_unviewed);
        $user->setAsSaved('alerts_unread', $alerts_unread);
    }

    /**
     * @param string        $contentType
     * @param int[]         $contentIds
     * @param string[]|null $actions
     * @param int           $maxXFVersion
     * @param User|null     $user
     * @param int|null      $viewDate
     */
    public function markAlertsReadForContentIds(string $contentType, array $contentIds, array $actions = null, int $maxXFVersion = 0, User $user = null, int $viewDate = null)
    {
        if ($maxXFVersion && \XF::$versionId > $maxXFVersion)
        {
            return;
        }

        if (empty($contentIds))
        {
            return;
        }

        $visitor = $user ? $user : \XF::visitor();
        $userId = $visitor->user_id;
        if (!$userId || !$visitor->alerts_unread)
        {
            return;
        }

        $viewDate = $viewDate ? $viewDate : \XF::$time;

        $db = $this->db();

        $actionFilter = $actions ? ' AND action in (' . $db->quote($actions) . ') ' : '';

        // Do a select first to reduce the amount of rows that can be touched for the update.
        // This hopefully reduces contention as must of the time it should just be a select, without any updates
        $alertIds = $db->fetchAllColumn(
            '
            SELECT alert_id
            FROM xf_user_alert
            WHERE alerted_user_id = ?
            AND (read_date = 0 OR view_date = 0)
            AND event_date < ?
            AND content_type IN (' . $db->quote($contentType) . ')
            AND content_id IN (' . $db->quote($contentIds) . ")
            {$actionFilter}
        ", [$userId, $viewDate]
        );

        if (empty($alertIds))
        {
            return;
        }

        $this->markAlertIdsAsReadAndViewed($user, $alertIds, $viewDate);
    }


    /**
     * @param UserAlertEntity $alert
     * @param int|null $readDate
     */
    public function markUserAlertRead(UserAlertEntity $alert, $readDate = null)
    {
        if ($readDate === null)
        {
            $readDate = \XF::$time;
        }

        if (!$alert->isUnread())
        {
            return;
        }

        $user = $alert->Receiver;

        $db = $this->db();
        $alert->setAsSaved('view_date', $readDate);
        $alert->setAsSaved('read_date', $readDate);

        $db->executeTransaction(function() use ($db, $alert, $user, $readDate)
        {
            $rows = $db->update('xf_user_alert',
                ['view_date' => $readDate, 'read_date' => $readDate],
                'alert_id = ?',
                $alert->alert_id
            );

            if (!$rows)
            {
                return;
            }

            $statement = $db->query("
                UPDATE xf_user
                SET alerts_unread = GREATEST(0, cast(alerts_unread AS SIGNED) - ?),
                    alerts_unviewed = GREATEST(0, cast(alerts_unviewed AS SIGNED) - ?)
                WHERE user_id = ?
            ", [1, $alert->alerted_user_id]);

            if ($user && $statement->rowsAffected())
            {
                $user->setAsSaved('alerts_unread', max(0, $user->alerts_unread - 1));
                $user->setAsSaved('alerts_unviewed', max(0, $user->alerts_unviewed - 1));
            }
        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    public function markUserAlertUnread(UserAlertEntity $alert, bool $disableAutoRead = true)
    {
        if ($alert->isUnread())
        {
            return;
        }

        $user = $alert->Receiver;

        $db = $this->db();

        $db->executeTransaction(function() use ($db, $alert, $user, $disableAutoRead)
        {
            $update = ['view_date' => 0, 'unread_date' => 0];
            if ($disableAutoRead)
            {
                $update['auto_read'] = 0;
            }

            $db->update('xf_user_alert',
                $update,
                'alert_id = ?',
                $alert->alert_id
            );

            $statement = $db->query("
                UPDATE xf_user
                SET alerts_unviewed = LEAST(alerts_unviewed + ?, 65535),
                    alerts_unread = LEAST(alerts_unread + ?, 65535)
                WHERE user_id = ?
            ", [1, $alert->alerted_user_id]);

            if ($user && $statement->rowsAffected())
            {
                $user->setAsSaved('alerts_unviewed', min(65535, $user->alerts_unviewed + 1));
                $user->setAsSaved('alerts_unread', min(65535, $user->alerts_unread + 1));
            }

        }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
    }

    /**
     * @param User $user
     * @return bool
     */
    public function updateUnviewedCountForUser(User $user)
    {
        $userId = $user->user_id;
        if (!$userId)
        {
            return false;
        }

        $db = \XF::db();
        $statement = $db->query('
            UPDATE xf_user
            SET alerts_unviewed = (SELECT COUNT(*)
                FROM xf_user_alert
                WHERE alerted_user_id = ? AND view_date = 0 AND summerize_id IS NULL)
            WHERE user_id = ?
        ', [$userId, $userId]);

        if (!$statement->rowsAffected())
        {
            return false;
        }

        // this doesn't need to be in a transaction as it is an advisory read
        $count = $db->fetchOne('
            SELECT alerts_unviewed 
            FROM xf_user 
            WHERE user_id = ?
        ', $userId);
        $user->setAsSaved('alerts_unviewed', $count);

        return $statement->rowsAffected() > 0;
    }

    /**
     * @param User $user
     * @return bool
     */
    public function updateUnreadCountForUser(User $user)
    {
        $userId = $user->user_id;
        if (!$userId)
        {
            return false;
        }

        $db = \XF::db();
        $statement = $db->query('
            UPDATE xf_user
            SET alerts_unread = (SELECT COUNT(*)
                FROM xf_user_alert
                WHERE alerted_user_id = ? AND alerts_unread = 0 AND summerize_id IS NULL)
            WHERE user_id = ?
        ', [$userId, $userId]);

        if (!$statement->rowsAffected())
        {
            return false;
        }

        // this doesn't need to be in a transaction as it is an advisory read
        $count = $db->fetchOne('
            SELECT alerts_unread 
            FROM xf_user 
            WHERE user_id = ?
        ', $userId);
        $user->setAsSaved('alerts_unread', $count);

        return true;
    }
}
