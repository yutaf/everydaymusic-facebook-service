<?php

class ArtistsRepository extends DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'artists';
    }

}