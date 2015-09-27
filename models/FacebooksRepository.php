<?php

class FacebooksRepository extends Yutaf\DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'facebooks';
    }
}