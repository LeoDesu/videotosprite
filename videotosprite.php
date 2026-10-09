#!/usr/bin/env php
<?php

declare(strict_types=1);

$cwd = getcwd() . '/';
$help = false;
if ($argc < 2) $help = true;
else if ($argc < 3) {
  if(substr($argv[$argk_last = array_key_last($argv)], 0, 1) === '-') {
    $help = true;
  } else {
    $input = path($cwd, $argv[$argk_last]);
    $output = substr($input, 0, strrpos($input, '.')).'_sprite';
  }
} else{
  if(substr($argv[$argk_last = array_key_last($argv)], 0, 1) === '-' || substr($argv[$argk_last-1], 0, 1) === '-') {
    $help = true;
  } else if ($argc > 3 && substr($argv[$argk_last-2], 0, 1) === '-'){
    $input = path($cwd, $argv[$argk_last]);
    $output = substr($input, 0, strrpos($input, '.')).'_sprite';
  } else {
    $input = path($cwd, $argv[$argk_last-1]);
    $output = path($cwd, $argv[$argk_last]);
  }
}

$generate = true;
$default_length = 320;
$out_width = 0;
$out_height = 0;
$rows = 10;
$cols = 10;
$capture = 0;
$tempdir = $cwd . "temp";

foreach($argv as $i => $arg){
  if(!$help && ($arg === '-h' || $arg === '--help')) {
    $help = true;
    continue;
  }
  if($arg === '--res') {
    //TODO: implement percentage based resulution scaling
    if(str_contains($argv[$i+1], 'x')) {
      $res = explode('x', $argv[$i+1]);
      $out_width = (int)$res[0];
      $out_height = (int)$res[1];
      if($out_height % 2 !== 0) $help = true;
      continue;
    } else {
      $out_length = intval($argv[$i+1]);
      if($out_length <= 0) {
        $help = true;
        continue;
      }
      $in_res = getVideoResolution($input);
      $out_res = calculateResolution($in_res, $out_length);
      $out_width = $out_res->width;
      $out_height = $out_res->height;
    }
  }
  if($arg === '--size') {
    if(!str_contains($argv[$i+1], 'x')) {
      $help = true;
      continue;
    }
    $size = explode('x', $argv[$i+1]);
    $rows = (int)$size[0];
    $cols = (int)$size[1];
    continue;
  }
  if($arg === '--capture') {
    $val = intval($argv[$i+1]);
    if($val >= 0 && $val < 10) $capture = (int)($argv[$i+1]);
    else $help = true;
    continue;
  }
  if($arg === '--tempdir') {
    $tempdir = substr($argv[$i+1], 0, 1) === '/' ? $argv[$i+1] : $cwd . $argv[$i+1];
  }
}

if($help){
  echo <<<HELP
usage: videotosprite [options] <input_video> <outputname>
videotosprite generates sprite sheet from a video

options:
-h, --help   show this help
--res        [width]x[height] pixels per sprite, value must be divisible by 2 (default 320)
             single value will refer as width, then height will be auto calculated
--size       demension of output sprite (default 10x10)
--capture    scale of 0-9 of a position to capture a frame from each sections (default 0)
--tempdir    temporary directory name to store temporary files (default temp, create if not exist)

HELP;
  $generate = false;
}

class Resolution {
    public function __construct(
        public int $width = 0,
        public int $height = 0,
    ) {}
}

function generate(string $input, string $output, string $tempdir, int $out_w, int $out_h, int $cols, int $rows, int $capture, bool $log = true) {
  $tempname = makeTempName($tempdir);
  $dirmade = false;
  if (!file_exists($tempdir)) $dirmade = mkdir($tempdir);
  $tempv = scaleDown($input, $tempname.'.mp4', $out_w, $out_h, $log);
  if($tempv === false){
    if ($dirmade) rmdir($tempdir);
    if ($log) echo "\e[31m[Sprite]\e[0m can not scale video to this resolution, please change the value\n";
    return false;
  }
  $images = extractImages($tempv, $cols*$rows, $tempname, $capture, $log);
  if(strpos($output, '.') === false) $output .= '.jpg';
  $filepath = combineSprite($images, $output, $cols, $rows, $tempname, $log);
  foreach($images as $image) {
      if(file_exists($image)) unlink($image);
  }
  if(file_exists($tempv)) unlink($tempv);
  if ($dirmade) rmdir($tempdir);
  return $filepath;
}

function makeTempName(string $tempdir) {
  return $tempdir . '/' . date("YmdHis") . 'videotosprite_tempfile';
}

function scaleDown(string $in, string $out, int $width, int $height, bool $log = true): string|false {
  if ($log) echo "\e[32m[Sprite]\e[0m scaling to: {$width}x{$height}\n";
  $result = exec("ffmpeg -loglevel 'quiet' -i '$in' -vf 'fps=10,scale={$width}:{$height}' '$out'");
  if($result === false) return false;
  return $out;
}
function calculateResolution(Resolution $in_res, int $length): Resolution{
    $scale = max($in_res->width, $in_res->height)/$length;
    $width = (int)($in_res->width/$scale);
    $height = (int)($in_res->height/$scale);
    $width = ($width%2) === 0 ? $width : $width-1;
    $height = ($height%2) === 0 ? $height : $height-1;
    return new Resolution($width, $height);
}

function getVideoResolution(string $vpath): Resolution{
    $cout = '';
    exec("ffprobe -v error -show_entries stream=width,height -of csv=s=x:p=0 '{$vpath}'", $cout);
    $res = implode('', $cout);
    if($res){
        $s = explode('x', $res);
        $res = new Resolution(intval($s[0]), intval($s[1]));
        return $res;
    }
    return new Resolution();
}

function extractImages(string $in, int $count = 100, string $tempname, int $capture, bool $log = true): array {
  $duration = exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '$in'");
  $section = (float)$duration / $count;
  $section_offset = (float)($section / 10 * $capture);
  if ($log) echo "\e[32m[Sprite]\e[0m duration: $duration\n";
  if ($log) echo "\e[32m[Sprite]\e[0m duration section: $section\n";
  $query = "";
  $outputs = [];
  if ($log) echo "\e[32m[Sprite]\e[0m extracting images...\n";
  for ($i = 0; $i < $count; $i++) {
    $ss = ($i * $section) + $section_offset;
    $query .= " -ss $ss -frames 1 '{$tempname}{$i}.jpg'";
    array_push($outputs, "{$tempname}{$i}.jpg");
  }
  exec("ffmpeg -loglevel 'quiet' -i '$in' $query");
  return $outputs;
}

function stack(array $inputs, string $out, string $mode = 'h') {
  $inputc = count($inputs);
  if($inputc < 1) return false;
  $query = '';
  foreach($inputs as $input) $query .= " -i '$input'";
  if($query !== ''){
    $result = exec("ffmpeg -loglevel 'quiet' $query -filter_complex '{$mode}stack=inputs=$inputc' '$out'");
    if(gettype($result) === 'string') return $out;
    return false;
  } else return false;
}

function combineSprite(array $images, string $out, int $width, int $height, string $tempname, bool $log = true) {
  $rows = array_chunk($images, $width);
  $row_outs = [];
  if ($log) echo "\e[32m[Sprite]\e[0m combining rows...\n";
  foreach($rows as $i => $row){
    $result = stack($row, "{$tempname}_row{$i}.jpg", 'h');
    if(gettype($result) === 'string') array_push($row_outs, $result);
  }
  if ($log) echo "\e[32m[Sprite]\e[0m combining result...\n";
  $result = stack($row_outs, $out, 'v');
  foreach($row_outs as $image) {
      if(file_exists($image)) unlink($image);
  }
  if(gettype($result) === 'string') return $result;
  return false;
}

function path(string $dir, string $arg) {
  return substr($arg, 0, 1) === '/' ? $arg : $dir . $arg;
}

if($generate){
    if($out_width === 0 && $out_height === 0){
        $in_res = getVideoResolution($input);
        $res = calculateResolution($in_res, $default_length);
        $out_width = $res->width;
        $out_height = $res->height;
    }
  generate($input, $output, $tempdir, $out_width, $out_height, $rows, $cols, $capture, true);
}
