<?php

function sendEmailLogData() {
    $appConfig = config::load();
    include  $appConfig['path_to_swift'] . 'swift_required.php';
    $emailDataFile = APPPATH . 'emaildata.txt';
    
    $efile = New File($emailDataFile);
    $efile->open("r+");
    // Отправляем содержимое файла на почту через определенный промежуток
    $email_send_file = New File('email_send_time.txt');
    $email_send_file->open("r");
    $send_file_time = $email_send_file->readData();
    $fileModDate = intval($send_file_time);
    $email_send_file->closeFp();
    $interval = $appConfig['email_send_interval'];

    if (
        time() - $fileModDate > $interval
            &&
        $appConfig['send_email'] == 1
            &&
        class_exists('Swift_Message')
    ) {
        $msg = $efile->readData();
        if (!empty($msg)) {
            $transport = Swift_SmtpTransport::newInstance($appConfig['smtp_transport']['smtp_server'], $appConfig['smtp_transport']['port'], 'ssl')
              ->setUsername($appConfig['smtp_transport']['username'])
              ->setPassword($appConfig['smtp_transport']['password']);
            // Create the Mailer using your created Transport
            $mailer = Swift_Mailer::newInstance($transport);
            // Create the message
            $message = Swift_Message::newInstance()
                // Give the message a subject
                ->setSubject('Лог обработки фотографий ' . date("r"))

                // Set the From address with an associative array
                ->setFrom(array('it@novdor.ru' => 'Admin'))
                ->setTo(array($appConfig['report_email']))
                // Give it a body
                ->setBody($msg);
            // Send the message
            $result = $mailer->send($message);
            
            /* Записываем время отправки письма */
            $email_send_file = New File('email_send_time.txt');
            if ($email_send_file) {
                $email_send_file->open("w+");
                $email_send_file->writeToFile(time());
                $email_send_file->closeFp();
            }
        }
        /* Очищаем файл */
        $newFile = New File($emailDataFile);
        $newFile->open("w+");
        $newFile->closeFp();
    }
    $efile->closeFp();
}

function getRenderFileName($filename) {
    return str_replace(" ", "_", $filename) . '_' . date('d-m-Y') . '_' . uniqid() . '.jpg';
}

/**
 * Обработка изображения
 * 
 * @param array $imageVal
 * @param array $appConfig
 * @param string $fioText
 * @return array
 */
function imgProcessing($imageVal, $appConfig, $fioText, $regionNameRu) {
    //include 'WideImage/WideImage.php';
    if (!class_exists('WideImage')) {
        exit('Class WideImage not found!');
    }
    $image = WideImage::load($imageVal['src']);
    list($width, $height, $type, $attr) = getimagesize($imageVal['src']);
    $imgRatio = round($width / $height, 2);
    $widescreen = 1.77;
    if ($appConfig['crop_img_to_widescreen'] && $imgRatio < $widescreen) {
        $cropedImg = $image->crop('center', 'bottom', $width, round($width / $widescreen) - $appConfig['footer_bg_color']);
        unset($image);
    } else {
        $cropedImg = $image;
    }

    $resizedImg = $cropedImg->resize(NULL, $appConfig['down_scaling_height']);

    $rgb = hex2rgb($appConfig['footer_bg_color']);
    $white = $resizedImg->allocateColor(
            $rgb[0],
            $rgb[1],
            $rgb[2]
        );

    // Добавляем внизу плашку
    $cropImg = $resizedImg->resizeCanvas('100%', '100%+' . $appConfig['footer_height'], 0, 0, $white);

    // обрезаем
    $newImage = $cropImg->crop(0, $appConfig['footer_height'], '100%', '100%');

    $choord1 = $imageVal['geolat'];
    $choord2 = $imageVal['geolong'];
    $text = $imageVal['road'] . ' '
         . mb_substr($imageVal['kmtitle'], mb_strpos($imageVal['kmtitle'], 'км')) .' '. ($imageVal['plus'] ? '+' : '-') . " {$imageVal['dist']} м.";
    if (isset($appConfig['color_text'])) {
        $hexTxtColor = hex2rgb($appConfig['color_text']);
        $textColor = $newImage->allocateColor($hexTxtColor[0], $hexTxtColor[1], $hexTxtColor[2]);
    } else {
        $textColor = $newImage->allocateColor(0, 0, 0);
    }

    $bottom = 15;
    /* 1 */
    $text = 'км';
    $canvas = $newImage->getCanvas();
    $canvas->useFont($appConfig['font'], 24, $textColor);
    $canvas->writeText('left + 10', 'bottom - ' . $bottom, $text);
    //$canvas->writeText('right - 220', 'bottom - 40', $text2);

    /* Столб */
    //$text = mb_substr($imageVal['km'], mb_strpos($imageVal['km'], 'км'));
    $text = $imageVal['km'];
    $count = 1;
    //$text = str_replace('км', '', $text, $count);
    $canvas = $newImage->getCanvas();
    $canvas->useFont($appConfig['font'], $appConfig['font_size'], $textColor);
    $canvas->writeText('left + 60', 'bottom - ' . $bottom, $text);

    /* Расстояние до столба */
    $text = '+' . $imageVal['dist'];
    $canvas = $newImage->getCanvas();
    $canvas->useFont($appConfig['font'], 24, $textColor);
    $canvas->writeText('left + 185', 'bottom - ' . $bottom, $text);

    /* Название дороги */
    $canvas = $newImage->getCanvas();
    $canvas->useFont($appConfig['font'], 12, $textColor);
    $canvas->writeText('left + 280', 'bottom - ' . $bottom, $imageVal['road']);

    /* Дата */
    $time = strtotime($imageVal['date']);
    $month = getRuMonth(date('m', $time));
    $text = date('d', $time) .'-'. mb_strtoupper(mb_substr($month, 0, 1)) . mb_substr($month, 1) .'-'. date('Y', $time);
    $canvas = $newImage->getCanvas();
    $canvas->useFont($appConfig['font'], 32, $textColor);
    $canvas->writeText('left + 565', 'bottom - ' . $bottom, $text);

    /* Время */
    $text = date('H:i:s');
    $canvas = $newImage->getCanvas();
    $canvas->useFont($appConfig['font'], 12, $textColor);
    $canvas->writeText('left + 950', 'bottom - ' . $bottom, $text);
    

    // Координаты
    $choordCanvas = $newImage->getCanvas();
    $choordCanvas->useFont($appConfig['font'], 12, $textColor);
    $choordCanvas->writeText( 'left + 1025', 'bottom - ' . $bottom,  "($choord1, $choord2)");

    $authorCanvas = $newImage->getCanvas();
    $authorCanvas->useFont($appConfig['font'], 12, $textColor);
    $authorCanvas->writeText( 'left + 950', 'bottom - 40', $fioText);

    $mask = WideImage::load($appConfig['logo_path']);
    $bigMask = $mask->resize('35%', '35%');

    $logoOffSet = 5;
    $maskImage = $newImage->merge($bigMask, 'right - ' . $logoOffSet, 'bottom', 100);
    
    $regionCanvas = $maskImage->getCanvas();
    $regionCanvas->useFont($appConfig['font'], 12, $textColor);
    $regionCanvas->writeText( 'left + 280', 'bottom - 50', $regionNameRu);
    return $maskImage;
}

function convertToCp1251($str) {
    return mb_convert_encoding($str, 'Windows-1251', 'UTF-8');
}

function convertToUtf8($str) {
    return mb_convert_encoding($str, 'UTF-8', 'Windows-1251');
}

/**
 * Функция вычисляет ближайший столб и определяет расстояние до ближ. точки
 * 
 * @param array $distance Массив точек(столбцов)
 * @param array $point Точка искомая
 */
function prepareDistance($distance, $roads, &$point) {
    
        $newdistance = array();
        foreach ($distance as $row){

            // Вычисляем расстояние между двумя точками
            $newdistance[$row['kmid']] = getDistance(
                    $row['gpslatitude'],
                    $row['gpslongitude'],
                    $point['gpslatitude'],
                    $point['gpslongitude']
                );
        }
        $notsort = $newdistance;

        asort($newdistance);

        reset($newdistance);
        $kmid = key($newdistance);
        $minValue = $newdistance[$kmid];
        next($newdistance);
        $seckmId = key($newdistance);
        // Вычисляем какой столб от начала(дальше или ближе)
        if ($kmid - $seckmId > 0) {
            // столб дальний
            $point['plus'] = TRUE;
        } else {
            // столб ближний
            $point['plus'] = FALSE;
        }

        $km = "";
        foreach ($distance as $row) {
            if ($kmid == $row['kmid']) {
                $roadId = $row['roadid'];
                $kmValue = $row['km'];
                $km = $row['title'];
            }
        }
        if (!$point['plus']) {
            $kmValue = $kmValue - 1;
        }

        $roadName = "";
        foreach ($roads as $road) {
            if ($roadId == $road['roadid']) {
                $roadName = $road['title'];
            }
        }

        if (floor($minValue) < 1000) {
            if (!$point['plus']) {
                $point['dist'] = 1000 - floor($minValue);
            } else {
                $point['dist'] = floor($minValue);
            }
        } else {
            $point['dist'] = '';
        }

        $point['kmtitle'] = $km;
        $point['km'] = $kmValue;
        $point['roadid'] = $roadId;
        $point['road'] = $roadName;
        
}

/**
 * Функция проверяет кол-во файлов в директории
 * Если кол-во больше лимита удаляет старые
 * @param string $pathToDir
 * @return int
 */
function clearCopyDir($pathToDir, $limitCountFiles) {
    $files = getFiles($pathToDir);
    $i = 0;
    $data = array();
    if (count($files) > $limitCountFiles) {
        $diff = count($files) - $limitCountFiles;
        foreach ($files as $key => $item) {
            $file = new File($item);
            $date = $file->getModTime();
            if ($date) {
                $data[] = array('file' => $item, 'date' => $date);
                $volume[$key] = $item;
                $edition[$key] = $date;
            }
        }
        array_multisort($edition, SORT_ASC, $volume, SORT_ASC, $data);
        unset($files);
        while ($diff > 0) {
            unlink($data[$i]['file']);
            $diff--;
            $i++;
        }

    }
    return $i;
}

/**
 * Список файлов из директории
 */
function getFiles($dir , $absPath = FALSE) {
    $filelist = array();

    //$folder = dirname(__FILE__);
    $folder = "{$dir}/";
    $i = 0;
    if ($handle = opendir($folder)) {
        while ($cv_file = readdir($handle)) {
            if (is_file($folder . $cv_file)) {
                $filelist[] = ($absPath ? $folder : "{$dir}/") . $cv_file;
            } elseif ($cv_file != "." && $cv_file != ".." && is_dir($folder . $cv_file)) {
                $filesArray = getFiles($folder . $cv_file);
                $filelist = array_merge($filelist, $filesArray);
            }

        }
        closedir($handle);
        return $filelist;
    } else {
        return FALSE;
        // Ошибка открытия директории
    }
}

/**
 * Кол-во файлов в директории
 * @param string $pathToDir
 * @return integer
 */
function getCountDirFiles($pathToDir) {
    $dir = new DirectoryIterator($pathToDir);
    $x = 0;
    foreach($dir as $file ){
        //$x += (isImage($pathToDir . '/' . $file)) ? 1 : 0;
        $x += ($file->isFile()) ? 1 : 0;
    }
    return $x;
}

/**
 * Проверяет является ли файл изображением
 * 
 * @param string $filename
 * @return boolean
 */
function isImage($filename) {
    $result = getimagesize($filename);
    return ($result !== NULL ? TRUE : FALSE);
}

function getPreparedFiles() {
    $filelist = array();

    $folder = "/upload/";
    if ($handle = opendir(dirname(__FILE__) . $folder)) {
        while ($entry = readdir($handle)) {
            if (is_file(dirname(__FILE__) . $folder . $entry)) {
                $filelist[] = $folder . $entry;
            }

        }
        closedir($handle);
    }
    return $filelist;
}

function redirect($url) {
    header("Location: " . $url);
    die();
}

function getAttrDataImg($imgPath) {
    //$imgPath = dirname(__FILE__) . $imgPath;
    if (!function_exists('exif_imagetype')) {
        exit('Include exif.dll library and restart web server!');
    }

    if (exif_imagetype($imgPath)) {
        $exif = exif_read_data($imgPath);
        if (!isset($exif['GPSLatitude'])) {
            return FALSE;
        }
        // Широта
        $latitude['degrees'] = getCoord( $exif['GPSLatitude'][0] );
        $latitude['minutes'] = getCoord( $exif['GPSLatitude'][1] );
        $latitude['seconds'] = getCoord( $exif['GPSLatitude'][2] );

        if ($latitude['degrees'] == 0 && $latitude['minutes'] == 0 && $latitude['seconds'] == 0) {
            //echo 'Координаты не найдены' . "\n";
            return FALSE;
        } else {
            $latitude['minutes'] += 60 * ($latitude['degrees'] - floor($latitude['degrees']));
            $latitude['degrees'] = floor($latitude['degrees']);

            $latitude['seconds'] += 60 * ($latitude['minutes'] - floor($latitude['minutes']));
            $latitude['minutes'] = floor($latitude['minutes']);

            // Долгота
            $longitude['degrees'] = getCoord( $exif['GPSLongitude'][0] );
            $longitude['minutes'] = getCoord( $exif['GPSLongitude'][1] );
            $longitude['seconds'] = getCoord( $exif['GPSLongitude'][2] );

            $longitude['minutes'] += 60 * ($longitude['degrees'] - floor($longitude['degrees']));
            $longitude['degrees'] = floor($longitude['degrees']);

            $longitude['seconds'] += 60 * ($longitude['minutes'] - floor($longitude['minutes']));
            $longitude['minutes'] = floor($longitude['minutes']);
            $degreesChar = "°";
            //$degrees = '&deg;';
            $m1 = ($exif['GPSLatitudeRef'] == 'S' ? '-' : '')
                    . $latitude['degrees'] . $degreesChar
                    . $latitude['minutes']
                    . "'" . $latitude['seconds'] . "\"N";
            $m2 = ($exif['GPSLongitudeRef'] == 'W' ? '-' : '')
                    . $longitude['degrees'] . $degreesChar
                    . $longitude['minutes'] . "'"
                    . $longitude['seconds'] . "\"E";
            return array(
                'date'         => isset($exif['DateTimeOriginal']) ? $exif['DateTimeOriginal'] : FALSE,
                'src'          => $imgPath,
                'gpslatitude'  => degreesToDecimal($latitude['degrees'], $latitude['minutes'], $latitude['seconds']),
                'gpslongitude' => degreesToDecimal($longitude['degrees'], $longitude['minutes'], $longitude['seconds']),
                'geolat'       => $m1,
                'geolong'      => $m2
            );
        }
    } else {
        return FALSE;
    }
}

/**
 * Возвращает расстояние между двумя точками
 * http://wiki.gis-lab.info/w/%D0%92%D1%8B%D1%87%D0%B8%D1%81%D0%BB%D0%B5%D0%BD%D0%B8%D0%B5_%D1%80%D0%B0%D1%81%D1%81%D1%82%D0%BE%D1%8F%D0%BD%D0%B8%D1%8F_%D0%B8_%D0%BD%D0%B0%D1%87%D0%B0%D0%BB%D1%8C%D0%BD%D0%BE%D0%B3%D0%BE_%D0%B0%D0%B7%D0%B8%D0%BC%D1%83%D1%82%D0%B0_%D0%BC%D0%B5%D0%B6%D0%B4%D1%83_%D0%B4%D0%B2%D1%83%D0%BC%D1%8F_%D1%82%D0%BE%D1%87%D0%BA%D0%B0%D0%BC%D0%B8_%D0%BD%D0%B0_%D1%81%D1%84%D0%B5%D1%80%D0%B5
 *
 * @param type $lat1
 * @param type $lon1
 * @param type $lat2
 * @param type $lon2
 * @return float
 */
function getDistance($lat1, $lon1, $lat2, $lon2) {
  $lat1 *= M_PI / 180;
  $lat2 *= M_PI / 180;
  $lon1 *= M_PI / 180;
  $lon2 *= M_PI / 180;

  $d_lon = $lon1 - $lon2;

  $slat1 = sin($lat1);
  $slat2 = sin($lat2);
  $clat1 = cos($lat1);
  $clat2 = cos($lat2);
  $sdelt = sin($d_lon);
  $cdelt = cos($d_lon);

  $y = pow($clat2 * $sdelt, 2) + pow($clat1 * $slat2 - $slat1 * $clat2 * $cdelt, 2);
  $x = $slat1 * $slat2 + $clat1 * $clat2 * $cdelt;

  return atan2(sqrt($y), $x) * 6372795;
}

function getCoord( $expr ) {
    $expr_p = explode( '/', $expr );
    if ($expr_p[0] == 0) {
        return 0;
    }
    return $expr_p[0] / $expr_p[1];
}

function degreesToDecimal($degrees, $minute, $seconds) {
    return round($degrees + $minute / 60 + $seconds / 3600, 6);
}

function hex2rgb($hex) {
   $hex = str_replace("#", "", $hex);

   if(strlen($hex) == 3) {
      $r = hexdec(substr($hex,0,1).substr($hex,0,1));
      $g = hexdec(substr($hex,1,1).substr($hex,1,1));
      $b = hexdec(substr($hex,2,1).substr($hex,2,1));
   } else {
      $r = hexdec(substr($hex,0,2));
      $g = hexdec(substr($hex,2,2));
      $b = hexdec(substr($hex,4,2));
   }
   $rgb = array($r, $g, $b);
   //return implode(",", $rgb); // returns the rgb values separated by commas
   return $rgb; // returns an array with the rgb values
}

function getRuMonth($mId) {
    switch ($mId){
        case 1: $m='января'; break;
        case 2: $m='февраля'; break;
        case 3: $m='марта'; break;
        case 4: $m='апреля'; break;
        case 5: $m='мая'; break;
        case 6: $m='июня'; break;
        case 7: $m='июля'; break;
        case 8: $m='августа'; break;
        case 9: $m='сентября'; break;
        case 10: $m='октября'; break;
        case 11: $m='ноября'; break;
        case 12: $m='декабря'; break;
    }
    return $m;
}

function log_message($msg = '') {
    if (!empty($msg) && function_exists('error_log') ) {
        error_log ($msg, 0);
    }
}