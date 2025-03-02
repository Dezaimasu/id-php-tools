<?php

use Core\DoomIntermissionConverter;
use Core\DoomWadParser;
use Tests\DoomIntermissionConverterTest;
use Tests\DoomWadParserTest;

require_once 'env.php';

spl_autoload_register(function($className){
    include __DIR__ . '\\' . $className . '.php';
});

//(new DoomIntermissionConverterTest())->test(); exit;
//(new DoomWadParserTest())->test(); exit;

//convertIntermissions();

//$wad = DoomWadParser::open(DIR_ROOT . '\thyint.pk3');
//$wad->savePicture('WIMAP3', DIR_ROOT);

function convertIntermissions(){
    $intermissions = [
        ['thyinterpic.wad', 'Ultimate DOOM E4: Thy Flesh Consumed', 'Skunk', 'D_INTER', 'MAPINFO'],
        ['INTMAPSG_GZ.wad', 'SIGIL', 'Oliacym', 'D_INTER', 'MAPINFO'],
        ['INTMAPD2_GZ.wad', 'Doom II', 'Oliacym', 'D_DM2INT', 'MAPINFO'],
        ['INTMAPEV_GZ.wad', 'TNT: Evilution', 'Oliacym', 'D_DM2INT', 'MAPINFO'],
        ['INTMAPPL_GZ.wad', 'The Plutonia Experiment', 'Oliacym', 'D_DM2INT', 'MAPINFO'],
        ['IntMaps_NRFL.wad', 'No Rest for the Living', 'Oliacym', 'D_DM2INT', 'MAPINFO'],
        ['INTMAPET_GZ.wad', 'Eviternity', 'Oliacym', 'D_DM2INT', 'ZMAPINFO'],
    ];

    foreach ($intermissions as [$wadFile, $title, $author, $music, $mapinfoLump]) {
        $wadPath = DIR_ROOT . '\\'. $wadFile;
        $outputDir = DIR_ROOT . '\\'. pathinfo($wadFile, PATHINFO_FILENAME);

        DoomIntermissionConverter::convert($wadPath, $outputDir, [
            'title'         => $title,
            'author'        => $author,
            'music'         => $music,
            'mapinfo_lump'  => $mapinfoLump,
        ], true, true);
    }
}
