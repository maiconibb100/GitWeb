<?php
declare(strict_types=1);
$root = realpath(__DIR__);
if ($root === false) throw new RuntimeException('Falha ao resolver o diretorio da aplicacao.');
return [
  'root'=>$root,
  'workspace'=>$root.DIRECTORY_SEPARATOR.'workspace',
  'storage'=>$root.DIRECTORY_SEPARATOR.'storage',
  'ca'=>$root.DIRECTORY_SEPARATOR.'certificates'.DIRECTORY_SEPARATOR.'cacert.pem',
  'max_file'=>1048576,
  'max_files'=>3000,
  'max_total'=>104857600,
];
