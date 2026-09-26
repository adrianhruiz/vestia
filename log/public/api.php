<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
try {
    $user=identity();if(!$user)json_out(['error'=>'Inicia sesión para ver tus registros.'],401);
    session_write_close();
    $db=slot_db($user['slot']);$logs=VestiaLogCapture::db();
    if(isset($_GET['job'])) {
        $id=filter_var($_GET['job'],FILTER_VALIDATE_INT);if(!$id)json_out(['error'=>'Trabajo no válido.'],400);
        $s=$db->prepare('SELECT id,type,status,progress,input_json,result_json,error,created_at,started_at,finished_at FROM jobs WHERE id=? AND user_id=?');$s->execute([$id,$user['id']]);$job=$s->fetch();
        if(!$job)json_out(['error'=>'Registro no encontrado.'],404);
        $s=$logs->prepare('SELECT * FROM calls WHERE environment=? AND user_id=? AND job_id=? ORDER BY started_at,id');$s->execute([$user['slot'],$user['id'],$id]);$calls=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($calls as &$call){$call['request']=json_decode($call['request_json']??'null',true);$call['response']=json_decode($call['response_json']??'null',true);unset($call['request_json'],$call['response_json']);}unset($call);
        $job['input']=json_decode($job['input_json']??'null',true);$job['result']=json_decode($job['result_json']??'null',true);unset($job['input_json'],$job['result_json']);
        json_out(['job'=>$job,'calls'=>$calls]);
    }
    $s=$db->prepare('SELECT id,type,status,progress,created_at,started_at,finished_at FROM jobs WHERE user_id=? ORDER BY id DESC LIMIT 200');$s->execute([$user['id']]);$jobs=$s->fetchAll();
    $s=$logs->prepare('SELECT job_id,COUNT(*) AS calls,SUM(total_tokens) AS tokens,SUM(duration_ms) AS duration_ms,MAX(model) AS model FROM calls WHERE environment=? AND user_id=? GROUP BY job_id');$s->execute([$user['slot'],$user['id']]);$by=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$by[$r['job_id']]=$r;
    foreach($jobs as &$job)$job['trace']=$by[$job['id']]??null;unset($job);
    json_out(['jobs'=>$jobs,'updated_at'=>gmdate('c')]);
} catch(Throwable $error){error_log('Vestia log: fallo al consultar registros.');json_out(['error'=>'No se pudieron consultar los registros. Inténtalo de nuevo.'],503);}
