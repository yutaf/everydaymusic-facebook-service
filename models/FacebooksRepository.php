<?php

class FacebooksRepository extends DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'facebooks';
    }
}