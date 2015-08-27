<?php

class ArtistsRepository extends Yutaf\DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'artists';
    }

}