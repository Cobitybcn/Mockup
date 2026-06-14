<?php
$db = new PDO("sqlite:storage/app.sqlite");
$stmt = $db->prepare("SELECT * FROM artworks WHERE job_id = :job_id");
$stmt->execute(['job_id' => 'job_test_real_1781266499_2157']);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
