#!/bin/env php
<?php

declare(strict_types=1);

$help = false;
if ($argc < 2) $help = true;
$generate = true;
$res_width = 320;
$res_height = -1;
$rows = 10;
$cols = 10;
$capture = 0;
$cwd = getcwd() . '/';
$tempdir = $cwd . "temp";

foreach($argv as $i => $arg){
  if(!$help && ($arg === '-h' || $arg === '--help')) {
    $help = true;
    continue;
  }
  if($arg === '--res') {
    if(str_contains($argv[$i+1], 'x')) {
      $res = explode('x', $argv[$i+1]);
      $res_width = (int)$res[0];
      $res_height = (int)$res[1];
      if($res_height % 2 !== 0) $help = true;
      continue;
    } else {
      $res_width = intval($argv[$i+1]);
      if($res_width <= 0) {
        $help = true;
        continue;
      }
      $origin_res = getVideoResolution(path($cwd, $argv[1]));
      $origin_width = (int)$origin_res['width'];
      $origin_height = (int)$origin_res['height'];
      $scale = $res_width / $origin_width;
      if(($origin_height * $scale) % 2 !== 0) {
        echo "not divisible by 2!\n";
        $help = true;
        continue;
      }
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
usage: videotosprite <input_video> <outputname> [options]
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

function generate(string $input, string $output, string $tempdir, int $res_w, int $res_h, int $width, int $height, int $capture, bool $log = true) {
  $tempname = makeTempName($tempdir);
  $dirmade = false;
  if (!file_exists($tempdir)) $dirmade = mkdir($tempdir);
  $tempv = scaleDown($input, $tempname.'.mp4', $res_w, $res_h, $log);
  if($tempv === false){
    if ($dirmade) rmdir($tempdir);
    if ($log) echo "\e[31m[Sprite]\e[0m can not scale video to this resolution, please change the value\n";
    return false;
  }
  $images = extractImages($tempv, $width*$height, $tempname, $capture, $log);
  if(strpos($output, '.') === false) $output .= '.jpg';
  $filepath = combineSprite($images, $output, $width, $height, $tempname, $log);
  foreach($images as $image) unlink($image);
  unlink($tempv);
  if ($dirmade) rmdir($tempdir);
  return $filepath;
}

function makeTempName(string $tempdir) {
  return $tempdir . '/' . date("YmdHis") . 'videotosprite_tempfile';
}

function scaleDown(string $in, string $out, int $width = 320, int $height = -1, bool $log = true): string|false {
  if ($log) echo "\e[32m[Sprite]\e[0m scaling down...\n";
  $result = exec("ffmpeg -loglevel 'quiet' -i '$in' -vf 'fps=10,scale={$width}:{$height}' '$out'");
  if($result === false) return false;
  return $out;
}

function getVideoResolution(string $video){
  exec("ffprobe -v error -print_format json -show_streams '{$video}'", $streams_json);
  $streams_json = implode('', $streams_json);
  /** @var array */
  $streams = json_decode($streams_json)->streams;
  $res = [];
  foreach($streams as $stream){
    //TODO: php waring: undefined property: stdClass::$width
    if(boolval($stream?->width) && boolval($stream?->height)){
      $res['width']  = $stream->width;
      $res['height'] = $stream->height;
    }
  }
  return $res;
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
  foreach($row_outs as $image) unlink($image);
  if(gettype($result) === 'string') return $result;
  return false;
}

function path(string $dir, string $arg) {
  return substr($arg, 0, 1) === '/' ? $arg : $dir . $arg;
}

if($generate){
  $input = path($cwd, $argv[1]);
  $output = path($cwd, $argv[2]);

  generate($input, $output, $tempdir, $res_width, $res_height, $rows, $cols, $capture, true);
}