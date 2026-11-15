<?php
   class Database_access {
   	private PDO $pdo;

   	public function __construct(PDO $pdo){
   	     $this->pdo = $pdo;
   }

   public function getByUsername(string $username){
   	 $sql = "SELECT a.user_id, a.username, a.password_hash, r.role_name FROM app_user a
	         JOIN role r ON a.role_id = r.role_id WHERE a.username = :username AND a.is_active";
   	 $stmt = $this->pdo->prepare($sql);
	 $stmt->execute(["username" => $username]);
   	 return $stmt->fetch();
   }

   }
?>