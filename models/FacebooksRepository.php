<?php

class FacebooksRepository extends Yutaf\DbRepository
{
    protected function setTableName()
    {
        $this->table_name = 'facebooks';
    }

    /**
     * fetch a record by facebook_user_id
     *
     * @param $facebook_user_id
     * @return mixed
     */
    public function fetchByFacebookUserId($facebook_user_id)
    {
        $sql = <<<EOL
SELECT * FROM {$this->table_name} WHERE facebook_user_id=:facebook_user_id
;
EOL;
        return $this->fetch($sql, array(':facebook_user_id'=>$facebook_user_id));
    }
}