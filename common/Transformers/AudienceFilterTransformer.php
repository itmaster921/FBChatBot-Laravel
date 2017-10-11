<?php namespace Common\Transformers;

use Common\Models\AudienceFilter;

class AudienceFilterTransformer extends BaseTransformer
{

    public function transform(AudienceFilter $filter)
    {
        return [
            'groups'    => $filter->groups?: [],
            'enabled'   => (bool)$filter->enabled,
            'join_type' => $filter->join_type?: 'and'
        ];
    }

}