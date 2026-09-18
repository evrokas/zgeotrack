<?php

// Extends the maker-generated usersClass (zeusfw core/classes/yaml/users.yaml)
// with the two lookups core/lib/UserLogin.php's login_post() calls
// directly (UsersClassEx::getUserAccount()) -- every app on this
// framework defines its own copy of this exact class, same convention
// zpms's web/ClassesEx.php already established.

class usersClassEx extends usersClass {
    static function getUserAccount($uname) {
        $sql = "SELECT * FROM users WHERE uname=:uname";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(":uname", $uname, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if ($row) {
            $rclass = new usersClass("users");
            $rclass->loadFields($row);
            return $rclass;
        } else return (null);
    }
}
