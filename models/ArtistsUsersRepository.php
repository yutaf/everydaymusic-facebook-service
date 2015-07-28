<?php

class ArtistsUsersRepository extends DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'artists_users';
    }

}