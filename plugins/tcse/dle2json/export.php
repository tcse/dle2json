<?php
/*
=====================================================
 DLE to JSON Exporter - by TCSE-cms.com and DeepSeek.com
 v0.2
-----------------------------------------------------
 https://tcse-cms.com/
-----------------------------------------------------
 Copyright (c) 2025 Vitaly V Chuyakov 
=====================================================
 This code is protected by copyright
=====================================================
 File: /plugins/tcse/dle2json/export.php
-----------------------------------------------------
 Purpose: Экспорт данных из DLE (категории и публикации) 
          в JSON формат с гибкой фильтрацией
-----------------------------------------------------
 Features:
 - Экспорт категорий с иерархией
 - Экспорт публикаций с фильтрацией
 - Поддержка сложных фильтров (диапазоны ID)
 - Генерация корректных абсолютных URL
 - Очистка контента от служебных тегов DLE
 - Поддержка популярных и недавно измененных новостей
 - Совместимость с PHP 7.3+
=====================================================
*/

// Параметры подключения к БД, можно взять из файла dle engine/data/dbconfig.php 
define("DBHOST", "localhost"); 
define("DBNAME", "");
define("DBUSER", "");
define("DBPASS", "");  
define("PREFIX", "");
define("USERPREFIX", "");
define("SITE_URL", "https://"); 

// Пароль для доступа к скрипту
define("ACCESS_PASS", "12345");

// Включаем полный вывод ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Функция для очистки данных
function cleanData($data) {
    if (is_array($data)) {
        return array_map('cleanData', $data);
    }
    return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
}

// Функция для очистки short_story
function cleanShortStory($text) {
    // Удаляем HTML комментарии
    $text = preg_replace('/<!--.*?-->/s', '', $text);
    // Удаляем специфичные комментарии DLE
    $text = preg_replace('/<!--MBegin:.*?-->/', '', $text);
    $text = preg_replace('/<!--MEnd-->/', '', $text);
    return trim($text);
}

// Функция для парсинга ID фильтров
function parseIdFilter($filter) {
    $ids = [];
    if (empty($filter)) return $ids;
    
    $parts = explode(',', $filter);
    
    foreach ($parts as $part) {
        if (strpos($part, '-') !== false) {
            list($start, $end) = explode('-', $part, 2);
            $start = (int)$start;
            $end = (int)$end;
            
            for ($i = min($start, $end); $i <= max($start, $end); $i++) {
                $ids[] = $i;
            }
        } else {
            $ids[] = (int)$part;
        }
    }
    
    return array_unique($ids);
}

// Проверка пароля
if (!isset($_GET['pass']) || $_GET['pass'] !== ACCESS_PASS) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Access denied', 'status' => 'error']));
}

// Подключение к базе данных
try {
    $db = new PDO('mysql:host='.DBHOST.';dbname='.DBNAME.';charset=utf8mb4', DBUSER, DBPASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Устанавливаем режим выборки
    $db->exec("SET NAMES utf8mb4");
} catch(PDOException $e) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Database connection failed', 'status' => 'error']));
}

// Обработка параметров
$fileName = isset($_GET['file']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['file']) : 'post';
$postLimit = isset($_GET['postLimit']) ? (int)$_GET['postLimit'] : 0;
$categoryId = isset($_GET['categoryid']) ? $_GET['categoryid'] : null;
$postId = isset($_GET['postid']) ? $_GET['postid'] : null;
$popular = isset($_GET['popular']) ? (int)$_GET['popular'] : 0;
$recentEdited = isset($_GET['recentedited']) ? (int)$_GET['recentedited'] : 0;

// Генерация имени файла на основе параметров
if ($categoryId) {
    $fileName .= '_cat_' . str_replace(',', '-', $categoryId);
}
if ($postId) {
    $fileName .= '_post_' . str_replace(',', '-', $postId);
}
if ($postLimit) {
    $fileName .= '_limit_' . $postLimit;
}
if ($popular) {
    $fileName .= '_popular_' . $popular;
}
if ($recentEdited) {
    $fileName .= '_recentedited_' . $recentEdited;
}
$fileName = substr($fileName, 0, 50); // Ограничиваем длину имени файла

try {
    // Получаем категории и создаем карту категорий для быстрого доступа
    $categories = $db->query("
        SELECT id, parentid, name, alt_name, icon 
        FROM ".PREFIX."_category 
        ORDER BY parentid, id
    ")->fetchAll(PDO::FETCH_ASSOC); // Явно указываем режим выборки
    
    $categoryMap = [];
    foreach ($categories as $cat) {
        $categoryMap[$cat['id']] = $cat;
    }
    $categories = cleanData($categories);

    // Формируем базовый SQL запрос
    $sql = "
        SELECT p.id, p.date, p.short_story, p.title, p.category, p.alt_name, p.approve, p.tags
    ";
    
    // Добавляем поля из _post_extras если нужно
    $join = "";
    $where = ["p.approve = 1"];
    $order = "p.date DESC";
    
    if ($popular || $recentEdited) {
        $sql .= ", e.news_read, e.editdate, e.related_ids";
        $join = "LEFT JOIN ".PREFIX."_post_extras e ON p.id = e.news_id";
        
        // Проверяем существование таблицы _post_extras
        try {
            $db->query("SELECT 1 FROM ".PREFIX."_post_extras LIMIT 1");
        } catch (PDOException $e) {
            throw new Exception("Table ".PREFIX."_post_extras does not exist or is not accessible");
        }
    }
    
    if ($popular) {
        $order = "e.news_read DESC";
        $postLimit = $popular;
    }
    
    if ($recentEdited) {
        $order = "e.editdate DESC";
        $postLimit = $recentEdited;
    }

    if ($categoryId) {
        $catIds = parseIdFilter($categoryId);
        if (!empty($catIds)) {
            $where[] = "p.category IN (" . implode(',', $catIds) . ")";
        }
    }

    if ($postId) {
        $postIds = parseIdFilter($postId);
        if (!empty($postIds)) {
            $where[] = "p.id IN (" . implode(',', $postIds) . ")";
        }
    }

    // Получаем публикации
    $sql = "
        $sql
        FROM ".PREFIX."_post p
        $join
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order
    ";
    
    if ($postLimit > 0) {
        $sql .= " LIMIT " . $postLimit;
    }

    $posts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); // Явно указываем режим выборки

    // Обрабатываем публикации
    foreach ($posts as &$post) {
        // Очищаем short_story
        $post['short_story'] = cleanShortStory($post['short_story']);
        
        // Обрабатываем категории (берем первую категорию)
        $catIds = explode(',', $post['category']);
        $firstCatId = $catIds[0];
        $catAltName = isset($categoryMap[$firstCatId]) ? $categoryMap[$firstCatId]['alt_name'] : $firstCatId;
        
        // Формируем URL
        $post['url'] = SITE_URL . "/$catAltName/{$post['id']}-{$post['alt_name']}.html";
        
        // Добавляем данные из post_extras, если они есть
        if (isset($post['news_read'])) {
            $post['views'] = (int)$post['news_read'];
            $post['last_edited'] = $post['editdate'];
            $post['related_ids'] = $post['related_ids'] ? explode(',', $post['related_ids']) : [];
            
            // Удаляем временные поля
            unset($post['news_read'], $post['editdate']);
        }
        
        $post = cleanData($post);
    }

    // Подготовка данных для экспорта
    $data = [
        'categories' => $categories,
        'posts' => $posts,
        'meta' => [
            'generated_at' => date('Y-m-d H:i:s'),
            'parameters' => [
                'categoryId' => $categoryId,
                'postId' => $postId,
                'postLimit' => $postLimit,
                'popular' => $popular,
                'recentEdited' => $recentEdited
            ],
            'counts' => [
                'categories' => count($categories),
                'posts' => count($posts)
            ]
        ]
    ];

    // Конвертация в JSON
    $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($jsonData === false) {
        throw new Exception('JSON encode error: '.json_last_error_msg());
    }

    // Проверка и создание директории
    $saveDir = __DIR__.'/data';
    if (!file_exists($saveDir)) {
        if (!mkdir($saveDir, 0755, true)) {
            throw new Exception("Cannot create directory $saveDir");
        }
    }

    // Сохранение файла
    $savePath = "$saveDir/$fileName.json";
    $bytes = file_put_contents($savePath, $jsonData);
    
    if ($bytes === false) {
        throw new Exception("Failed to write to $savePath");
    }

    // Успешный результат
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => "Data exported successfully",
        'details' => [
            'file_path' => $savePath,
            'file_url' => SITE_URL . "/plugins/tcse/dle2json/data/$fileName.json",
            'file_size' => $bytes,
            'categories' => count($categories),
            'posts' => count($posts),
            'filters' => [
                'base' => SITE_URL . "/plugins/tcse/dle2json/export.php?pass=12345",
                'by_category' => SITE_URL . "/plugins/tcse/dle2json/export.php?pass=12345&categoryid=ID",
                'by_post' => SITE_URL . "/plugins/tcse/dle2json/export.php?pass=12345&postid=ID",
                'popular' => SITE_URL . "/plugins/tcse/dle2json/export.php?pass=12345&popular=NUM",
                'recent_edited' => SITE_URL . "/plugins/tcse/dle2json/export.php?pass=12345&recentedited=NUM",
                'combine' => SITE_URL . "/plugins/tcse/dle2json/export.php?pass=12345&categoryid=ID&postid=ID&popular=NUM&recentedited=NUM&postLimit=NUM"
            ]
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    file_put_contents(__DIR__.'/error.log', date('[Y-m-d H:i:s] ').$e->getMessage().PHP_EOL, FILE_APPEND);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'hint' => "SITE_URL definition "
    ], JSON_PRETTY_PRINT);
}