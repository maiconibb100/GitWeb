<?php
declare(strict_types=1);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cfg(): array { static $c; if ($c === null) $c = require __DIR__ . '/config.php'; return $c; }
function safeName(string $v): string { if (!preg_match('/^[A-Za-z0-9_.-]+$/', $v)) throw new RuntimeException('Nome invalido.'); return $v; }
function safeBranch(string $v): string { $v=trim($v); if($v==='' || !preg_match('#^[A-Za-z0-9._/-]+$#',$v) || strpos($v,'..')!==false || $v[0]==='/') throw new RuntimeException('Branch invalida.'); return $v; }
function safePath(string $p): string {
    $p=ltrim(str_replace('\\','/',trim($p)),'/');
    if($p==='') return '';
    if(strpos($p,"\0")!==false || preg_match('/^[A-Za-z]:/',$p)) throw new RuntimeException('Path invalido.');
    foreach(explode('/',$p) as $x) if($x==='' || $x==='.' || $x==='..') throw new RuntimeException('Path relativo invalido.');
    return $p;
}
function joinPath(string $base,string $rel): string { $rel=safePath($rel); return rtrim($base,'\\/').($rel===''?'':DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel)); }
function ensureDir(string $p): void { if(!is_dir($p) && !mkdir($p,0770,true) && !is_dir($p)) throw new RuntimeException('Falha ao criar: '.$p); }
function parseRepo(string $url): array {
    $url=rtrim(trim($url),'/'); if(substr($url,-4)==='.git') $url=substr($url,0,-4);
    if(!preg_match('#^https://github\.com/([^/]+)/([^/]+)$#i',$url,$m)) throw new RuntimeException('Use https://github.com/OWNER/REPO');
    return [safeName($m[1]),safeName($m[2]),$url];
}
function sourceRepo(): array { if(empty($_SESSION['repo'])) throw new RuntimeException('Conecte um repositorio primeiro.'); return $_SESSION['repo']; }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verifyCsrf(): void { if(!isset($_POST['csrf']) || !hash_equals(csrf(),(string)$_POST['csrf'])) throw new RuntimeException('CSRF invalido.'); }
function workspace(array $r): string { return cfg()['workspace'].DIRECTORY_SEPARATOR.$r['owner'].DIRECTORY_SEPARATOR.$r['repo']; }
function stateFile(array $r): string { return cfg()['storage'].DIRECTORY_SEPARATOR.'state'.DIRECTORY_SEPARATOR.$r['owner'].'--'.$r['repo'].'--'.preg_replace('/[^A-Za-z0-9_.-]/','_',$r['branch']).'.json'; }
function loadState(array $r): array { $f=stateFile($r); if(!is_file($f)) return ['files'=>[]]; $x=json_decode((string)file_get_contents($f),true); return is_array($x)?$x:['files'=>[]]; }
function saveState(array $r,array $s): void { if(file_put_contents(stateFile($r),json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false) throw new RuntimeException('Falha ao salvar estado.'); }
function scanFiles(string $root): array {
    if(!is_dir($root)) throw new RuntimeException('Workspace inexistente. Clone primeiro.');
    $files=[]; $total=0; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){ if(!$f->isFile()) continue; $rel=str_replace('\\','/',substr($f->getPathname(),strlen(rtrim($root,'\\/'))+1)); $rel=safePath($rel); $size=$f->getSize(); $total+=$size; $files[$rel]=['file'=>$f->getPathname(),'size'=>$size,'hash'=>hash_file('sha256',$f->getPathname())]; }
    ksort($files); return ['files'=>$files,'total'=>$total];
}
function statuses(array $r): array {
    $state=loadState($r); $scan=scanFiles(workspace($r)); $out=[];
    $paths=array_unique(array_merge(array_keys($state['files']??[]),array_keys($scan['files']))); sort($paths);
    foreach($paths as $p){
        $old=$state['files'][$p]??null; $cur=$scan['files'][$p]??null;
        $status=$old===null?'NOVO':($cur===null?'REMOVIDO':(($cur['hash']??'')===($old['base_hash']??'')?'IGUAL':'ALTERADO'));
        $out[]=['path'=>$p,'status'=>$status,'size'=>$cur['size']??0];
    }
    return $out;
}
function removeTree(string $path): void {
    if(is_file($path)||is_link($path)){ if(!unlink($path)) throw new RuntimeException('Falha ao excluir arquivo.'); return; }
    if(!is_dir($path)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){ $ok=$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); if(!$ok) throw new RuntimeException('Falha ao excluir item.'); }
    if(!rmdir($path)) throw new RuntimeException('Falha ao excluir diretorio.');
}

final class GH {
    private $r,$ca;
    function __construct(array $r){$this->r=$r;$this->ca=cfg()['ca'];if(!is_readable($this->ca))throw new RuntimeException('cacert.pem ilegivel.');}
    private function base(): string { return '/repos/'.rawurlencode($this->r['owner']).'/'.rawurlencode($this->r['repo']); }
    function request(string $method,string $path,?array $payload=null,array $allowed=[]): array {
        $headers=['Accept: application/vnd.github+json','User-Agent: WebGit-API-PHP/4.0','X-GitHub-Api-Version: 2022-11-28'];
        $token=(string)($this->r['token']??''); if($token==='') $token=(string)($this->r['password']??''); if($token!=='') $headers[]='Authorization: Bearer '.$token;
        $ch=curl_init('https://api.github.com'.$path); $opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>180,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>$this->ca];
        if($payload!==null){$headers[]='Content-Type: application/json';$opt[CURLOPT_HTTPHEADER]=$headers;$opt[CURLOPT_POSTFIELDS]=json_encode($payload);}
        curl_setopt_array($ch,$opt);$body=curl_exec($ch);$eno=curl_errno($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false||$eno)throw new RuntimeException('cURL '.$eno.': '.$err);$json=json_decode($body,true);
        if(($code<200||$code>=300)&&!in_array($code,$allowed,true))throw new RuntimeException($method.' '.$path.' retornou GitHub HTTP '.$code.': '.(is_array($json)?($json['message']??substr($body,0,400)):substr($body,0,400)));
        return ['code'=>$code,'json'=>is_array($json)?$json:[]];
    }
    function repo():array{return $this->request('GET',$this->base())['json'];}
    function contents(string $p=''):array{$s=$p===''?'':'/'.implode('/',array_map('rawurlencode',explode('/',safePath($p))));return $this->request('GET',$this->base().'/contents'.$s.'?ref='.rawurlencode($this->r['branch']))['json'];}
    function tree():array{return $this->request('GET',$this->base().'/git/trees/'.rawurlencode($this->r['branch']).'?recursive=1')['json'];}
    function ref():array{return $this->request('GET',$this->base().'/git/ref/heads/'.rawurlencode($this->r['branch']))['json'];}
    function commit(string $sha):array{return $this->request('GET',$this->base().'/git/commits/'.rawurlencode($sha))['json'];}
    function blob(string $content):string{$x=$this->request('POST',$this->base().'/git/blobs',['content'=>base64_encode($content),'encoding'=>'base64'])['json'];return(string)$x['sha'];}
    function createTree(string $baseTree,array $entries):string{$x=$this->request('POST',$this->base().'/git/trees',['base_tree'=>$baseTree,'tree'=>$entries])['json'];return(string)$x['sha'];}
    function createCommit(string $msg,string $tree,string $parent):string{$x=$this->request('POST',$this->base().'/git/commits',['message'=>$msg,'tree'=>$tree,'parents'=>[$parent]])['json'];return(string)$x['sha'];}
    function updateRef(string $sha):void{$this->request('PATCH',$this->base().'/git/refs/heads/'.rawurlencode($this->r['branch']),['sha'=>$sha,'force'=>false]);}
}

function rebuildState(array $r, GH $gh): void {
    $ref=$gh->ref(); $head=(string)($ref['object']['sha']??''); $tree=$gh->tree(); $scan=scanFiles(workspace($r));
    $remote=[]; foreach($tree['tree']??[] as $e) if(($e['type']??'')==='blob') $remote[$e['path']]=$e['sha'];
    $state=['repo'=>$r['owner'].'/'.$r['repo'],'branch'=>$r['branch'],'base_commit'=>$head,'updated_at'=>date(DATE_ATOM),'files'=>[]];
    foreach($scan['files'] as $p=>$f) $state['files'][$p]=['remote_sha'=>$remote[$p]??null,'base_hash'=>$f['hash'],'size'=>$f['size']];
    saveState($r,$state);
}
