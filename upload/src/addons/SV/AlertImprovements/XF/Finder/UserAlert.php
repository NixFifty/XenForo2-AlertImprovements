<?php


namespace SV\AlertImprovements\XF\Finder;

use XF\Mvc\Entity\AbstractCollection;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Finder
 */
class UserAlert extends XFCP_UserAlert
{
    /**
     * @var \Closure|null
     */
    protected $shimSource;
    /**
     * @var bool
     */
    protected $shimCollectionViewable = false;

    public function shimSource(\Closure $shimSource = null)
    {
        $this->shimSource = $shimSource;
    }

    /**
     * XF2.1 function, XF2.2 technically has markInaccessibleAlertsReadIfNeeded but it's usage in some cases is broken
     *
     * @param bool $shimCollectionViewable
     */
    public function markUnviewableAsUnread(bool $shimCollectionViewable = true)
    {
        $this->shimCollectionViewable = $shimCollectionViewable;
    }

    public function getShimmedCollection(array $entities): AbstractCollection
    {
        return new MarkReadAlertArrayCollection($entities);
    }

    /**
     * @param int|null $limit
     * @param int|null $offset
     * @return AbstractCollection
     */
    public function fetch($limit = null, $offset = null)
    {
        $shimSource = $this->shimSource;

        if ($shimSource)
        {
            if ($limit === null)
            {
                $limit = $this->limit;
            }
            if ($offset === null)
            {
                $offset = $this->offset;
            }

            $output = $shimSource($limit, $offset);
            if ($output !== null)
            {
                if ($this->shimCollectionViewable)
                {
                    return $this->getShimmedCollection($output);
                }

                return $this->em->getBasicCollection($output);
            }
        }

        $collection = parent::fetch($limit, $offset);

        if ($this->shimCollectionViewable && $collection instanceof AbstractCollection)
        {
            $collection = $this->getShimmedCollection($collection->toArray());
        }

        return $collection;
    }

    /**
     * @param array $rawEntities
     * @returns \SV\AlertImprovements\XF\Entity\UserAlert[]
     * @return array
     */
    public function materializeAlerts(array $rawEntities)
    {
        $output = [];
        $em = $this->em;

        $id = $this->structure->primaryKey;
        $shortname = $this->structure->shortName;

        // bulk load users, really should track all joins/Withs.
        $userIds = [];
        foreach ($rawEntities as $rawEntity)
        {
            if (!$em->findCached('XF:User', $rawEntity['user_id']))
            {
                $userIds[$rawEntity['user_id']] = true;
            }
            if (!$em->findCached('XF:User', $rawEntity['alerted_user_id']))
            {
                $userIds[$rawEntity['alerted_user_id']] = true;
            }
        }
        if ($userIds)
        {
            $userIds = array_keys($userIds);
            $em->getFinder('XF:User')->whereIds($userIds)->fetch();
        }

        // materialize raw entities into Entities
        foreach ($rawEntities as $rawEntity)
        {
            $relations = [
                'User'     => $em->findCached('XF:User', $rawEntity['user_id']),
                'Receiver' => $em->findCached('XF:User', $rawEntity['alerted_user_id']),
            ];
            $output[$rawEntity[$id]] = $em->instantiateEntity($shortname, $rawEntity, $relations);
        }

        return $output;
    }
}

