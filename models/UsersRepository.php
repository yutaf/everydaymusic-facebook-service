<?php

class UsersRepository extends DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'users';
    }
}