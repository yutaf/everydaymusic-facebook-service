<?php

class ArtistsUsersRepository extends Yutaf\DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'artists_users';
    }

}