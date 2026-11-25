<?php
declare(strict_types=1);
require_once __DIR__.'/ConfigurationScope.php';

/** Read-only, token-based configuration contract. Never includes/evaluates module code. */
final class MCBSourceContract
{
    private static function identity(array $profile): string
    {
        // Adding an exact-source fallback must not invalidate existing structural contracts.
        $roots=array_map('strtolower',$profile['roots']);sort($roots,SORT_STRING);$files=$profile['files'];sort($files,SORT_STRING);$runtime=$profile['runtimeData']??[];sort($runtime,SORT_STRING);
        $identity=['adapter'=>$profile['adapter'],'roots'=>$roots,'files'=>$files,'configurationHash'=>$profile['configurationHash'],'metadataBlob'=>$profile['metadataBlob'],'dataFiles'=>$profile['dataFiles']??[],'runtimeData'=>$runtime];
        if (isset($profile['scope'])) $identity+=['scope'=>$profile['scope'],'attributes'=>$profile['attributes']??[]];
        return hash('sha256',MCBEngine::canonical($identity));
    }
    private static function opens(PhpToken $t): bool { return $t->text==='{' || in_array($t->id,[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true); }
    private static function hashTokens(array $tokens): string
    {
        return hash('sha256',json_encode(array_map(static fn($t)=>$t->id===T_OPEN_TAG?'<?php':$t->text,$tokens),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    /** Includes declarations, registration/lifecycle roots, and recursively referenced methods.
     * Dynamic dispatch conservatively includes every method in the dependency set.
     */
    private static function parse(string $source): array
    {
        if (!class_exists('PhpToken') || strlen($source)>8388608) throw new RuntimeException('Source tokenizer unavailable or source too large.');
        $tokens=array_values(array_filter(PhpToken::tokenize($source,TOKEN_PARSE),static fn($t)=>!$t->isIgnorable()));
        $methods=[];$members=[];$globals=[];$classes=[];$surface=[];$depth=0;$classDepth=null;$class=null;$pending=false;$memberStart=0;
        for ($i=0,$n=count($tokens);$i<$n;$i++) {
            $t=$tokens[$i];
            if ($class===null && in_array($t->id,[T_CLASS,T_TRAIT,T_INTERFACE],true) && ($tokens[$i-1]->id??null)!==T_DOUBLE_COLON) {
                if (($tokens[$i+1]->id??null)!==T_STRING) throw new RuntimeException('Anonymous top-level class requires review.');
                $class=strtolower($tokens[$i+1]->text);if (isset($classes[$class])) throw new RuntimeException('Ambiguous class name.');
                $surface=[];while ($globals && in_array($globals[count($globals)-1]->id,[T_FINAL,T_ABSTRACT],true)) array_unshift($surface,array_pop($globals));
                $pending=true;
            }
            if ($class!==null && $classDepth!==null && $depth===$classDepth && $t->id===T_FUNCTION) {
                $start=$memberStart;$surface=array_slice($surface,0,count($surface)-($i-$start));
                $j=$i+1;if (($tokens[$j]->text??'')==='&') ++$j;
                if (($tokens[$j]->id??null)!==T_STRING) throw new RuntimeException('Unsupported class member.');
                $name=$class.'::'.strtolower($tokens[$j]->text);if (isset($methods[$name])) throw new RuntimeException('Duplicate method.');
                for (;$j<$n && !in_array($tokens[$j]->text,['{',';'],true);$j++) {}
                if ($j===$n) throw new RuntimeException('Incomplete method.');
                if ($tokens[$j]->text==='{') {
                    $braces=1;while (++$j<$n) { if (self::opens($tokens[$j])) ++$braces;elseif ($tokens[$j]->text==='}' && --$braces===0) break; }
                }
                if ($j===$n) throw new RuntimeException('Incomplete method body.');
                $methods[$name]=array_slice($tokens,$start,$j-$start+1);$i=$j;$memberStart=$j+1;continue;
            }
            if ($class!==null && $classDepth!==null && $depth===$classDepth && in_array($t->id,[T_CONST,T_VARIABLE],true)) {
                $start=$memberStart;$surface=array_slice($surface,0,count($surface)-($i-$start));
                for ($j=$i;$j<$n && $tokens[$j]->text!==';';$j++) {}
                if ($j===$n) throw new RuntimeException('Incomplete member declaration.');
                $body=array_slice($tokens,$start,$j-$start+1);$nest=0;$expect=$t->id===T_CONST;
                for ($k=$i;$k<$j;$k++) {
                    $v=$tokens[$k];
                    if (($expect && $v->id===T_STRING && ($tokens[$k+1]->text??'')==='=') || ($nest===0 && $v->id===T_VARIABLE)) { $members[$class.'::'.strtolower(ltrim($v->text,'$'))]=$body;$expect=false; }
                    if (in_array($v->text,['[','('],true)) ++$nest;elseif (in_array($v->text,[']',')'],true)) --$nest;
                    elseif ($v->text===',' && $nest===0 && $t->id===T_CONST) $expect=true;
                }
                $i=$j;$memberStart=$j+1;continue;
            }
            if ($class===null) $globals[]=$t;else $surface[]=$t;
            if (self::opens($t)) { ++$depth;if ($pending) { $classDepth=$depth;$pending=false;$memberStart=$i+1; } }
            elseif ($t->text==='}') {
                if ($depth===$classDepth) { $classes[$class]=$surface;$class=null;$classDepth=null; }
                --$depth;
                if ($depth===$classDepth) $memberStart=$i+1;
            }
            elseif ($t->text===';' && $depth===$classDepth) $memberStart=$i+1;
        }
        if ($depth!==0 || $class!==null) throw new RuntimeException('Unrecognized source structure.');
        return ['globals'=>$globals,'classes'=>$classes,'methods'=>$methods,'members'=>$members];
    }
    public static function project(string $source,array $roots): array { return self::projectSources(['module.php'=>$source],$roots); }
    private static function localClosures(array $tokens): array
    {
        $known=[];$other=[];
        foreach ($tokens as $i=>$t) if ($t->id===T_VARIABLE && ($tokens[$i+1]->text??'')==='=') {
            $j=$i+2;if (($tokens[$j]->id??null)===T_STATIC) ++$j;
            if (in_array($tokens[$j]->id??null,[T_FN,T_FUNCTION],true)) $known[$t->text]=true;else $other[$t->text]=true;
        }
        return array_diff_key($known,$other);
    }
    private static function opaqueCallback(array $tokens,int $i,array $closures): bool
    {
        $positions=['array_map'=>1,'array_filter'=>2,'array_reduce'=>2,'array_walk'=>2,'array_walk_recursive'=>2,'usort'=>2,'uasort'=>2,'uksort'=>2,'preg_replace_callback'=>2,'register_shutdown_function'=>1,'register_tick_function'=>1,'set_error_handler'=>1,'set_exception_handler'=>1,'spl_autoload_register'=>1];
        $position=$positions[strtolower($tokens[$i]->text)]??0;
        if (!$position || ($tokens[$i+1]->text??'')!=='(') return false;
        $arg=1;$depth=0;
        for ($j=$i+2,$n=count($tokens);$j<$n;$j++) {
            $t=$tokens[$j];if ($depth===0 && $arg===$position) {
                if ($t->text===')') return false; // Optional callback omitted.
                if ($t->id===T_STATIC) $t=$tokens[++$j];
                if ($t->id===T_CONSTANT_ENCAPSED_STRING && str_contains($t->text,'\\')) return true;
                return !in_array($t->id,[T_FN,T_FUNCTION,T_CONSTANT_ENCAPSED_STRING],true)
                    && !($t->id===T_VARIABLE && isset($closures[$t->text]))
                    && !($t->id===T_STRING && strtolower($t->text)==='null');
            }
            if ($t->text==='(' || $t->text==='[' || self::opens($t)) ++$depth;
            elseif (in_array($t->text,[')',']','}'],true)) { if ($depth===0) return false;--$depth; }
            elseif ($t->text===',' && $depth===0) ++$arg;
        }
        return true;
    }
    private static function includePath(array $tokens,int $i,string $file): string
    {
        $j=$i+1;if (($tokens[$j]->text??'')==='(') ++$j;
        if (($tokens[$j]->id??null)!==T_DIR || ($tokens[$j+1]->text??'')!=='.' || ($tokens[$j+2]->id??null)!==T_CONSTANT_ENCAPSED_STRING) throw new RuntimeException('Dynamic source include requires review.');
        $literal=$tokens[$j+2]->text;if (str_contains($literal,'\\') || !in_array($tokens[$j+3]->text??'',[';',')'],true)) throw new RuntimeException('Complex source include requires review.');
        $parts=explode('/',dirname($file).'/'.substr($literal,1,-1));$out=[];
        foreach ($parts as $part) {
            if ($part==='' || $part==='.') continue;
            if ($part==='..' && $out && end($out)!=='..') array_pop($out);else $out[]=$part;
        }
        return implode('/',$out);
    }
    public static function includePaths(string $source,string $file): array
    {
        if (!class_exists('PhpToken')) return [];
        $tokens=array_values(array_filter(PhpToken::tokenize($source,TOKEN_PARSE),static fn($t)=>!$t->isIgnorable()));$out=[];
        foreach ($tokens as $i=>$t) if (in_array($t->id,[T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE],true)) {
            try { $out[]=self::includePath($tokens,$i,$file); } catch (RuntimeException $e) {} // Relevant dynamic includes are refused by projection.
        }
        return array_values(array_unique($out));
    }
    public static function projectSources(array $sources,array $roots,string $scope='',array $attributes=[]): array
    {
        $nodes=[];$byName=[];$classNames=[];$memberNames=[];$owners=[];$rootMethods=[];$methodNames=[];$queue=[];
        if ($scope==='configuration-v2') {
            // Orphan files do not execute and cannot affect configuration. Follow bootstrap includes, not the directory inventory.
            $active=[];$pending=['module.php'];
            while ($pending) {
                $file=array_pop($pending);if (isset($active[$file])) continue;
                if (!isset($sources[$file])) throw new RuntimeException('Missing required PHP dependency '.$file.'.');
                $parsed=self::parse($sources[$file]);$active[$file]=$sources[$file];
                foreach ($parsed['globals'] as $i=>$t) if (in_array($t->id,[T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE],true)) $pending[]=self::includePath($parsed['globals'],$i,$file);
            }
            $sources=$active;
        }
        $allMethods=[];
        foreach ($sources as $source) {
            $parsed=self::parse($source);foreach ($parsed['methods'] as $key=>$body) $allMethods[$key]=$body;
            if ($scope==='configuration-v2') foreach ($parsed['globals'] as $i=>$t) if ($t->id===T_FUNCTION && ($parsed['globals'][$i+1]->id??null)===T_STRING) $allMethods['global::@'.strtolower($parsed['globals'][$i+1]->text)]=$parsed['globals'];
        }
        $sensitive=$scope==='configuration-v2'?MCBConfigurationScope::sensitive($allMethods,$attributes):[];
        $lifecycle=[];$native=[];
        if ($scope==='configuration-v2') {
            $named=[];foreach ($allMethods as $key=>$body) $named[substr($key,strrpos($key,'::')+2)][]=$key;
            foreach (['lifecycle'=>['create','applychanges'],'native'=>array_diff(array_map('strtolower',$roots),['create','applychanges'])] as $kind=>$seeds) {
                $pending=$seeds;$reached=[];
                while ($pending) {
                    $name=array_pop($pending);if(isset($reached[$name]))continue;$reached[$name]=true;
                    foreach($named[$name]??[] as $key) foreach(MCBConfigurationScope::calls($allMethods[$key]) as $called=>$_) $pending[]=$called;
                }
                if($kind==='lifecycle')$lifecycle=$reached;else $native=$reached;
            }
        }
        foreach ($sources as $file=>$source) {
            $parsed=self::parse($source);$key=$file.'::globals';$globals=$parsed['globals'];
            if ($scope==='configuration-v2') $globals=MCBConfigurationScope::declaration($globals,false);
            $nodes[$key]=$globals;
            $bootstrap=implode('',array_map(static fn($t)=>$t->text,$globals));
            if ($scope!=='configuration-v2' || $file==='module.php' || preg_replace('/<\?php|declare\([^;]*\);/','',$bootstrap)!=='') $queue[]=$key;
            foreach ($parsed['classes'] as $name=>$body) {
                if ($scope==='configuration-v2') $body=MCBConfigurationScope::declaration($body,true);
                $key=$file.'::'.$name.'::declaration';$nodes[$key]=$body;$classNames[$name][]=$key;
            }
            foreach ($parsed['methods'] as $name=>$body) {
                [$class,$method]=explode('::',$name,2);$key=$file.'::'.$name;$nodes[$key]=$body;
                if ($scope==='configuration-v2' && isset($lifecycle[$method]) && !isset($native[$method]) && (isset($sensitive[$method]) || in_array($method,['create','applychanges'],true))) $nodes[$key]=MCBConfigurationScope::lifecycle($body,$sensitive,$attributes);
                $owners[$key]=$file.'::'.$class.'::declaration';$byName[$method][]=$key;$methodNames[$key]=$method;
                if ($file==='module.php') $rootMethods[$method][]=$key;
            }
            foreach ($parsed['members'] as $name=>$body) {
                [$class,$member]=explode('::',$name,2);$key=$file.'::'.$name.'::member';$nodes[$key]=$body;$memberNames[$member][]=$key;
            }
        }
        foreach ($roots as $root) { $root=strtolower($root);if (!isset($rootMethods[$root])) throw new RuntimeException('Missing contract root '.$root.'.');foreach ($rootMethods[$root] as $key) $queue[]=$key; }
        $selected=[];$dataPaths=[];$rawFallback=false;
        while ($queue) {
            $key=array_pop($queue);if (isset($selected[$key])) continue;$body=$nodes[$key];$selected[$key]=self::hashTokens($body);
            if ($scope==='configuration-v2' && !str_ends_with($key,'::globals')) $queue[]=explode('::',$key,2)[0].'::globals';
            $closures=self::localClosures($body);
            if (isset($owners[$key])) $queue[]=$owners[$key];
            else foreach ($owners as $methodKey=>$owner) if ($owner===$key && str_starts_with($methodNames[$methodKey],'__') && ($scope!=='configuration-v2' || in_array($methodNames[$methodKey],['__call','__callstatic'],true))) $queue[]=$methodKey;
            foreach ($body as $i=>$t) {
                if (in_array($t->id,[T_INCLUDE,T_INCLUDE_ONCE,T_REQUIRE,T_REQUIRE_ONCE],true)) {
                    $path=self::includePath($body,$i,explode('::',$key,2)[0]);
                    if (!isset($sources[$path])) throw new RuntimeException('Missing source dependency '.$path.'.');
                }
                if ($t->id===T_CONSTANT_ENCAPSED_STRING && preg_match('/\.json[\'\"]$/i',$t->text) && ($body[$i-1]->text??'')==='.' && ($body[$i-2]->id??null)===T_DIR) {
                    $parts=explode('/',dirname(explode('::',$key,2)[0]).'/'.substr($t->text,1,-1));$path=[];
                    foreach ($parts as $part) { if ($part==='' || $part==='.') continue;if ($part==='..' && $path && end($path)!=='..') array_pop($path);else $path[]=$part; }
                    $dataPaths[implode('/',$path)]=true;
                }
                // Over-approximate references across classes/traits, including literal callbacks.
                $candidate=null;
                if (in_array($t->id,[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED,T_NAME_RELATIVE],true)) $candidate=strtolower($t->text);
                elseif ($t->id===T_CONSTANT_ENCAPSED_STRING) $candidate=strtolower(substr($t->text,1,-1));
                if ($candidate!==null) {
                    if (str_contains($candidate,'::')) $candidate=substr($candidate,strrpos($candidate,'::')+2);
                    $call=in_array($body[$i-1]->id??null,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true) && ($body[$i+1]->text??'')==='(';
                    $literal=$t->id===T_CONSTANT_ENCAPSED_STRING && (isset($owners[$key]) || str_ends_with($key,'::globals'));
                    if ($scope==='configuration-v2' && $literal) $literal=str_contains($t->text,'::') || (($body[$i-1]->text??'')===',' && ($body[$i-2]->text??'')==='$this');
                    if ($call || $literal) {
                        $references=$byName[$candidate]??[];
                        $receiver=$body[$i-2]??null;
                        if (($body[$i-1]->id??null)===T_DOUBLE_COLON && $receiver!==null && in_array($receiver->id,[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true) && !in_array(strtolower($receiver->text),['self','static','parent'],true)) {
                            $className=strtolower($receiver->text);$className=substr($className,strrpos('\\'.$className,'\\'));
                            // The receiver is explicit: unrelated classes with the same method name are not dependencies.
                            $own=array_values(array_filter($references,static fn($ref)=>explode('::',$ref)[1]===$className));if ($own) $references=$own;
                        }
                        if ($receiver!==null && in_array(strtolower($receiver->text),['$this','self'],true)) {
                            $parts=explode('::',$key);$own=$parts[0].'::'.$parts[1].'::'.$candidate;
                            if (isset($owners[$own]) && array_filter(array_slice($nodes[$own],0,4),static fn($token)=>$token->id===T_PRIVATE)) $references=[$own];
                        }
                        foreach ($references as $ref) $queue[]=$ref;
                    }
                    if (in_array($body[$i-1]->id??null,[T_OBJECT_OPERATOR,T_NULLSAFE_OBJECT_OPERATOR,T_DOUBLE_COLON],true)) {
                        $references=$memberNames[$candidate]??[];$receiver=$body[$i-2]??null;
                        if (($body[$i-1]->id??null)===T_DOUBLE_COLON && $receiver!==null && in_array($receiver->id,[T_STRING,T_NAME_QUALIFIED,T_NAME_FULLY_QUALIFIED],true) && strtolower($receiver->text)!=='static' && strtolower($receiver->text)!=='parent') {
                            $className=strtolower($receiver->text);if ($className==='self') $className=explode('::',$key)[1];else $className=substr($className,strrpos('\\'.$className,'\\'));
                            $own=array_values(array_filter($references,static fn($ref)=>explode('::',$ref)[1]===$className));if ($own) $references=$own;
                        }
                        foreach ($references as $ref) $queue[]=$ref;
                    }
                    $short=substr($candidate,strrpos('\\'.$candidate,'\\'));foreach ($classNames[$short]??[] as $ref) $queue[]=$ref;
                    if ($scope==='configuration-v2' && ($body[$i-1]->id??null)===T_NEW) foreach ($owners as $ref=>$owner) if (in_array($owner,$classNames[$short]??[],true) && in_array($methodNames[$ref],['__construct','__destruct'],true)) $queue[]=$ref;
                }
                if ($t->id===T_VARIABLE && ($body[$i-1]->id??null)===T_DOUBLE_COLON) foreach ($memberNames[strtolower(ltrim($t->text,'$'))]??[] as $ref) $queue[]=$ref;
                $dynamic=($t->id===T_OBJECT_OPERATOR || $t->id===T_NULLSAFE_OBJECT_OPERATOR || $t->id===T_DOUBLE_COLON) && ($body[$i+1]->id??null)!==T_STRING;
                $variableCall=$t->id===T_VARIABLE && ($body[$i+1]->text??'')==='(' && !isset($closures[$t->text]);
                $reflective=$t->id===T_STRING && (str_starts_with(strtolower($t->text),'reflection') || in_array(strtolower($t->text),['get_class_methods','get_class_vars','get_object_vars'],true));
                $rawFallback=$rawFallback || $reflective || $t->id===T_EVAL;
                $opaque=$t->id===T_EVAL || $reflective || ($t->id===T_STRING && (in_array(strtolower($t->text),['call_user_func','call_user_func_array','forward_static_call','forward_static_call_array','fromcallable','preg_replace_callback_array'],true) || preg_match('/^array_(u?diff|u?intersect).*u/Di',strtolower($t->text))));
                if ($dynamic || $opaque || $variableCall || ($t->id===T_STRING && self::opaqueCallback($body,$i,$closures))) foreach ($nodes as $ref=>$_) if (!isset($selected[$ref])) $queue[]=$ref;
            }
        }
        ksort($selected,SORT_STRING);
        $contract=['schema'=>'MCB.SourceContract.v1','nodes'=>$selected];
        if ($rawFallback) { $raw=array_map(static fn($source)=>hash('sha256',$source),$sources);ksort($raw,SORT_STRING);$contract['opaqueSource']=$raw; }
        $paths=array_keys($dataPaths);sort($paths,SORT_STRING);
        return ['hash'=>hash('sha256',MCBEngine::canonical($contract)),'methods'=>array_values(array_intersect(array_keys($selected),array_keys($owners))),'nodes'=>$selected,'dataPaths'=>$paths];
    }
    public static function identify(array $profiles,array $sources,string $metadata,array $dataFiles=[]): array
    {
        $projects=[];$reasonGroups=['configuration'=>[],'legacy'=>[]];
        // Prefer the material contract so future compatible edits keep the same identity.
        usort($profiles,static fn($a,$b)=>(int)(($b['scope']??'')==='configuration-v2')<=>(int)(($a['scope']??'')==='configuration-v2'));
        foreach ($profiles as $profile) {
            $reasons=&$reasonGroups[($profile['scope']??'')==='configuration-v2'?'configuration':'legacy'];
            try {
                $scope=$profile['scope']??'';
                if ($scope!=='configuration-v2' && (array_diff(array_keys($sources),$profile['files']) || array_diff($profile['files'],array_keys($sources)))) { $reasons[]='PHP dependency list';continue; }
                if (!class_exists('PhpToken')) {
                    $exact=$profile['exactFiles']??[];
                    if (array_diff_key($exact,$sources) || ($scope!=='configuration-v2' && array_diff_key($sources,$exact))) { $reasons[]='PHP tokenizer unavailable; exact source review needed';continue; }
                    foreach ($exact as $path=>$hashes) if (!in_array(hash('sha256',$sources[$path]),$hashes,true)) { $reasons[]='PHP tokenizer unavailable; exact source review needed';continue 2; }
                    $project=['dataPaths'=>array_keys($profile['dataFiles']??[])];
                } else {
                    $key=MCBEngine::canonical([$profile['roots'],$scope,$profile['attributes']??[]]);
                    $project=$projects[$key]??=self::projectSources($sources,$profile['roots'],$scope,$profile['attributes']??[]);
                    if (!hash_equals($profile['configurationHash'],$project['hash'])) {
                        $changed=[];foreach ($profile['nodes']??[] as $node=>$hash) if (($project['nodes'][$node]??null)!==$hash) $changed[]=$node;
                        foreach (array_diff_key($project['nodes'],$profile['nodes']??[]) as $node=>$_) if (isset($profile['nodes'])) $changed[]=$node;
                        $reasons[]='configuration methods or declarations'.($changed?': '.implode(', ',array_slice(array_unique($changed),0,8)):'');continue;
                    }
                }
                if (($profile['metadataBlob']??'')!==$metadata) { $reasons[]='module identity/API metadata';continue; }
                $relevant=[];foreach ($project['dataPaths'] as $path) if (!in_array($path,$profile['runtimeData']??[],true)) $relevant[$path]=$dataFiles[$path]??null;
                if (MCBEngine::canonical($profile['dataFiles']??[])!==MCBEngine::canonical($relevant)) { $reasons[]='configuration data files';continue; }
                return ['contract'=>['schema'=>'MCB.SourceContract.v1','id'=>self::identity($profile),'adapter'=>$profile['adapter']],'contractReview'=>[]];
            } catch (Throwable $e) { $reasons[]='source could not be analyzed: '.$e->getMessage(); }
        }
        $reasons=$reasonGroups['configuration']?:$reasonGroups['legacy'];
        return ['contract'=>null,'contractReview'=>array_values(array_unique($reasons))];
    }
    public static function reviewed(array $rule,array $source): bool
    {
        if (self::validContract($rule,$source)) return true;
        if (!empty($source['contractChecked'])) return false;
        if (!in_array($source['moduleBlob']??'',$rule['sourceBlobs']??[$rule['sourceBlob']],true)) return false;
        foreach ($rule['auditFiles']??[] as $path=>$allowed) if (!in_array($source['auditFiles'][$path]??'',is_array($allowed)?$allowed:[$allowed],true)) return false;
        return true;
    }
    public static function validContract(array $rule,array $source): bool
    {
        $contract=$source['contract']??null;if (!is_array($contract) || ($contract['schema']??'')!=='MCB.SourceContract.v1') return false;
        foreach ($rule['contracts']??[] as $profile) if (($contract['adapter']??null)===$profile['adapter'] && hash_equals(self::identity($profile),(string)($contract['id']??''))) return true;
        return false;
    }
    public static function compatible(array $entry,array $current,array $rule): bool
    {
        if (empty($current['digest']) || !self::reviewed($rule,$current)) return false;
        if (($entry['audited']??false)!==true) return self::revalidationIssue($entry,$current,$rule)===null;
        if (hash_equals((string)($entry['source']['digest']??''),$current['digest'])) return true;
        if (!isset($entry['source']['contractChecked']) && isset($current['legacyDigest']) && hash_equals((string)($entry['source']['digest']??''),$current['legacyDigest'])) return true;
        if (!self::validContract($rule,$entry['source']) || !self::validContract($rule,$current) || $entry['adapter']!==$current['contract']['adapter']) return false;
        if (($entry['source']['contract']??null)===($current['contract']??null)) return true;
        // A reviewed migration may link earlier full-source contracts to the same material configuration contract.
        foreach ($rule['contracts']??[] as $profile) if (($profile['scope']??'')==='configuration-v2') {
            $ids=array_merge([self::identity($profile)],$profile['equivalentContracts']??[]);
            if (in_array($entry['source']['contract']['id'],$ids,true) && in_array($current['contract']['id'],$ids,true) && $profile['adapter']===$entry['adapter']) return true;
        }
        return false;
    }
    public static function compatibilityIssue(array $entry,array $current,array $rule): string
    {
        if(empty($current['digest']))return 'installed source is unavailable';
        if(!self::reviewed($rule,$current))return implode('; ',$current['contractReview']??[])?:'installed configuration code has no matching review';
        if(($entry['audited']??false)!==true)return self::revalidationIssue($entry,$current,$rule)??'';
        return 'the saved and installed export/import contracts have not been verified as equivalent';
    }
    private static function revalidationIssue(array $entry,array $current,array $rule): ?string
    {
        if (!self::validContract($rule,$current) || ($entry['adapter']??null)!==$current['contract']['adapter']) return 'saved export format has no matching verified material contract';
        foreach ($rule['contracts']??[] as $profile) {
            if (($profile['scope']??'')!=='configuration-v2' || !hash_equals(self::identity($profile),$current['contract']['id'])) continue;
            $required=['module.php'=>true,'module.json'=>true];
            foreach ($profile['nodes']??[] as $node=>$_) $required[explode('::',$node,2)[0]]=true;
            foreach ($profile['dataFiles']??[] as $path=>$_) $required[$path]=true;
            foreach ($required as $path=>$_) {
                $saved=$entry['source']['files'][$path]??null;$installed=$current['files'][$path]??null;
                if (!is_string($saved) || !preg_match('/^[a-f0-9]{64}$/D',$saved) || !is_string($installed)) return 'saved configuration-source evidence is missing for '.$path;
                if (!hash_equals($saved,$installed)) return 'saved configuration-source evidence differs for '.$path;
            }
            // Coverage can be established later only from matching captured source evidence and a consistent saved native payload.
            try { return isset($entry['native']) && MCBEngine::canonical($entry['native'])===MCBEngine::canonical(MCBAdapters::export($entry))?null:'saved native export is inconsistent with captured configuration'; }
            catch (Throwable $e) { return 'saved native export cannot be reconstructed from captured configuration'; }
        }
        return 'saved capture has no reconstructible configuration-source review';
    }
}
