<?php
class MySQLConnection
{
    private $host;
    private $username;
    private $password;
    private $database;
    private $conn;

    public function __construct($host, $username, $password, $database)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->database = $database;
        $this->conn = null;
    }

    public function connect()
    {
        $this->conn = new mysqli(
            $this->host,
            $this->username,
            $this->password,
            $this->database
        );

        if ($this->conn->connect_error) {
            die("MySQL bağlantısı başarısız: " . $this->conn->connect_error);
        }
    }

    public function closeConnection()
    {
        if ($this->conn != null) {
            $this->conn->close();
        }
    }

    public function getConnection()
    {
        return $this->conn;
    }
}
