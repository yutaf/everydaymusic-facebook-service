<?php

class UsersRepository extends Yutaf\DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'users';
    }
}