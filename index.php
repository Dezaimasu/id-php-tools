<?php

use Core\DoomIntermissionConverter;
use Core\DoomWadParser;
use Tests\DoomIntermissionConverterTest;
use Tests\DoomWadParserTest;

spl_autoload_register(function($className){
    include __DIR__ . '\\' . $className . '.php';
});

(new DoomIntermissionConverterTest())->test();
//(new DoomWadParserTest())->test();

//DoomIntermissionConverter::convert('D:\Code\_wads\INTMAPSG_GZ.wad', 'D:\Code\_wads\INTMAPSG_GZ', [
//    'title'         => 'SIGIL',
//    'author'        => 'Oliacym',
//    'music'         => 'D_INTER',
//    'mapinfo_lump'  => 'MAPINFO',
//], false, true);

//$wad = DoomWadParser::open('E:\Doom\IWADs\DOOM.WAD');
//$wad->savePicture('FLOOR0_1', 'E:\Doom\IWADs');
