<?php

namespace Joindin\Api\Model;

use Exception;
use PDO;
use Joindin\Api\Request;
use Teapot\StatusCode\Http;

class OAuthModel
{
    // @codingStandardsIgnoreStart
    protected $_db;
    // @codingStandardsIgnoreEnd
    protected $request;

    /**
     * Object constructor, sets up the db and some objects need request too
     *
     * @param PDO     $db The database connection handle
     * @param Request $request
     */
    public function __construct(PDO $db, Request $request)
    {
        $this->setDb($db);
        $this->setRequest($request);
    }

    /**
     * @return PDO
     */
    public function getDb()
    {
        return $this->_db;
    }

    /**
     * @param PDO $db
     */
    public function setDb(PDO $db)
    {
        $this->_db = $db;
    }

    /**
     * @param Request $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @return string
     */
    public function getBase()
    {
        return $this->request->getBase();
    }

    /**
     * @return int
     */
    public function getVersion()
    {
        return $this->request->version;
    }

    /**
     * verifyAccessToken
     *
     * @param string $token The valid access token
     *
     * @return int The ID of the user this belongs to
     */
    public function verifyAccessToken($token)
    {
        $sql  = 'select id, user_id from oauth_access_tokens'
                . ' where access_token=:access_token';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["access_token" => $token]);
        $result = $stmt->fetch();

        // log that we used this token
        $update_sql  = 'update oauth_access_tokens '
                       . ' set last_used_date = NOW()'
                       . ' where id = :id';
        $update_stmt = $this->_db->prepare($update_sql);
        $update_stmt->execute(["id" => $result['id']]);

        // return the user ID this token belongs to
        return $result['user_id'];
    }

    /**
     * Create an access token for a username and password combination
     *
     * @param  string $clientId aka consumer_key
     * @param  string $username username
     * @param  string $password password
     *
     * @return false|array access token and user Uri
     */
    public function createAccessTokenFromPassword(string $clientId, string $username, string $password): array|false
    {
        // is the username/password combination correct?
        $userId = $this->getUserId($username, $password);

        if (!$userId) {
            return false;
        }

        $accessToken = $this->createAccessToken($clientId, $userId);

        // we also want to send back the logged in user's uri
        $userUri = $this->getUserUri($userId);

        return ['access_token' => $accessToken, 'user_uri' => $userUri];
    }

    /**
     * Retrieve the user's record from the database.
     *
     * @param  string $username user's username
     * @param  string $password user's password
     *
     * @throws Exception
     * @return int|false            user's id on success or false
     */
    protected function getUserId(string $username, string $password): int|false
    {
        $sql  = 'SELECT ID, password, email, verified FROM user
            WHERE username=:username';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["username" => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        if ($result['verified'] != 1) {
            throw new Exception("Not verified", Http::UNAUTHORIZED);
        }

        if (!password_verify(md5($password), $result['password'])) {
            return false;
        }

        return $result['ID'];
    }

    /**
     * Retrieve the user's uri based on the user id.
     *
     * @param  int $userId user's id.
     *
     * @return string         user's uri.
     */
    public function getUserUri(int $userId): string
    {
        return $this->getBase() . '/' . $this->getVersion() . '/users/' . $userId;
    }

    /**
     * Generate, store and return a new access token to the user
     *
     * @param string $consumer_key the identifier for the consumer
     * @param int|string $user_id  the user granting access
     *
     * @return false|string access token
     */
    public function createAccessToken(string $consumer_key, int|string $user_id): false|string
    {
        $hash        = $this->generateToken();
        $accessToken = substr($hash, 0, 16);

        $sql = "INSERT INTO oauth_access_tokens set
                access_token = :access_token,
                consumer_key = :consumer_key,
                user_id = :user_id,
                last_used_date = NOW()
                ";

        $stmt   = $this->_db->prepare($sql);
        $result = $stmt->execute(
            [
                'access_token' => $accessToken,
                'consumer_key' => $consumer_key,
                'user_id'      => $user_id,
            ]
        );

        if ($result) {
            return $accessToken;
        }

        return false;
    }

    /**
     * generateToken
     *
     * taken mostly from
     * http://toys.lerdorf.com/archives/55-Writing-an-OAuth-Provider-Service.html
     *
     * @return string
     */
    public function generateToken(): string
    {
        $fp      = fopen('/dev/urandom', 'rb');
        $entropy = fread($fp, 32);
        fclose($fp);

        $hash = sha1($entropy); // sha1 gives us a 40-byte hash

        return $hash;
    }

    /**
     * Expire any tokens belonging to the list of $clientIds that are over a day old.
     *
     * @param  array $clientIds list of client ids to expire
     *
     * @return void
     */
    public function expireOldTokens(array $clientIds): void
    {
        foreach ($clientIds as $clientId) {
            $sql = "DELETE FROM oauth_access_tokens WHERE
                    consumer_key=:consumer_key AND last_used_date < :expiry_date";

            $stmt = $this->_db->prepare($sql);
            $stmt->execute(
                [
                    'consumer_key' => $clientId,
                    'expiry_date'  => date('Y-m-d', strtotime('-1 day'))
                ]
            );
        }
    }

    /**
     *  Get the name of the consumer that this user was authenticated with
     *
     * @param ?string $token The valid access token
     *
     * @access public
     *
     * @return string An identifier for the OAuth consumer
     */
    public function getConsumerName(?string $token): string
    {
        $sql  = 'select at.consumer_key, c.id, c.application '
                . 'from oauth_access_tokens at '
                . 'left join oauth_consumers c using (consumer_key) '
                . 'where at.access_token=:access_token ';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["access_token" => $token]);
        $result = $stmt->fetch();

        // what did we get? Might have been an oauth app, a special one (like web2)
        // or something else.
        if ($result['application']) {
            return $result['application'];
        }

        return "joind.in";
    }

    /**
     * Check whether a supplied consumer is permitted to use
     * the "password" grant type during the OAuth process
     *
     * @param string $key    An OAuth consumer key to check
     * @param string $secret The corresponding consumer secret
     *
     * @return bool Whether the consumer is permitted
     */
    public function isClientPermittedPasswordGrant($key, $secret)
    {
        $sql  = 'select c.enable_password_grant from '
                . 'oauth_consumers c '
                . 'where c.consumer_key=:key '
                . 'and c.consumer_secret=:secret '
                . 'and c.enable_password_grant = 1';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["key" => $key, "secret" => $secret]);

        if ($stmt->fetch()) {
            return true;
        }

        return false;
    }

    /**
     * Check whether the consumer related to an access token may use
     * the "password" grant type during the OAuth process
     *
     * @param string $token The access token
     *
     * @return bool Whether the consumer is permitted
     */
    public function isAccessTokenPermittedPasswordGrant($token)
    {
        $sql  = 'select c.enable_password_grant from '
                . 'oauth_consumers c '
                . 'inner join oauth_access_tokens at using (consumer_key) '
                . 'where at.access_token = :token '
                . 'and c.enable_password_grant = 1';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["token" => $token]);

        if ($stmt->fetch()) {
            return true;
        }

        return false;
    }

    /**
     * Check that this password is current for this user ID
     *
     * Useful when confirming old password before changing to a new one
     *
     * @param int    $userId   The ID of the user we're checking
     * @param string $password Their supplied password
     *
     * @return boolean True if the password is correct, false otherwise
     */
    public function reverifyUserPassword($userId, $password)
    {
        $sql  = 'SELECT ID, password FROM user
            WHERE ID = :user_id
            AND verified = 1';
        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["user_id" => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            if (password_verify(md5($password), $result['password'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create an access token for someone identified by twitter username
     *
     * @param  string $clientId        aka consumer_key (of the joindin client)
     * @param  string $twitterUsername User's twitter nick
     *                                 (that we just got back from authenticating them)
     *
     * @return false|array                   access token
     */
    public function createAccessTokenFromTwitterUsername($clientId, $twitterUsername)
    {
        $sql = "select ID from user "
               . "where twitter_username = :twitter_username "
               . "and verified = 1";

        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["twitter_username" => $twitterUsername]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        $userId = $result['ID'];

        $accessToken = $this->createAccessToken($clientId, $userId);

        // we also want to send back the logged in user's uri
        $userUri = $this->getUserUri($userId);

        return ['access_token' => $accessToken, 'user_uri' => $userUri];
    }

    /**
     * $values is expected to contain:
     * * name: The users "real name"
     * * screen_name: The twitter-username
     * * email (optional): The users email-address
     *
     * @param string $clientId
     * @param array  $values
     *
     * @throws Exception
     * @return array
     */
    public function createUserFromTwitterUsername($clientId, array $values)
    {
        $sql = "select ID from user "
               . "where twitter_username = :twitter_username";

        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["twitter_username" => $values['screen_name']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // User has not yet been validated
            throw new Exception('User exists already');
        }

        if (!isset($values['email'])) {
            $values['email'] = 'email@example.net';
        }

        $sql = "insert into user(username, full_name, twitter_username, email, verified, active, admin) "
               . "values(:screen_name, :name, :screen_name, :email, 1, 1, 0)";

        $stmt = $this->_db->prepare($sql);

        if (
            !$stmt->execute([
                'screen_name' => $values['screen_name'],
                'name'        => $values['name'],
                'email'       => $values['email'],
            ])
        ) {
            throw new Exception('Something went wrong');
        }

        return $this->createAccessTokenFromTwitterUsername($clientId, $values['screen_name']);
    }

    /**
     * Create an access token for someone identified by email address via a
     * third party authentication system such as Facebook
     *
     * @param  string $clientId aka consumer_key (of the joindin client)
     * @param  string $email    User's email address (that we just got back from authenticating them)
     * @param  string $fullName User's full name from Facebook
     * @param  string $userName Username to be created if not found
     *
     * @return array|false              Array of access token and user uri on success or false or failure
     */
    public function createAccessTokenFromTrustedEmail($clientId, $email, $fullName = '', $userName = '')
    {
        $sql = "
            SELECT ID from user
            WHERE email = :email
            AND verified = 1";

        $stmt = $this->_db->prepare($sql);
        $stmt->execute(["email" => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result && $fullName && $userName) {
            $result = $this->createUserFromTrustedEmail($email, $fullName, $userName);
        }

        $userId = $result['ID'];

        if (!$userId) {
            return false;
        }

        $accessToken = $this->createAccessToken($clientId, $userId);

        // we also want to send back the logged in user's uri
        $userUri = $this->getUserUri($userId);

        return ['access_token' => $accessToken, 'user_uri' => $userUri];
    }

    protected function createUserFromTrustedEmail($email, $fullName, $userName)
    {
        $sql = "
            INSERT INTO user
            SET email = :email,
                verified = 1,
                active = 1,
                full_name = :fullName,
                username = :userName
        ";

        $stmt = $this->_db->prepare($sql);
        $stmt->execute(
            [
                "email"    => $email,
                'fullName' => $fullName,
                'userName' => $userName
            ]
        );

        return [
            'ID' => $this->_db->lastInsertId(),
        ];
    }
}
