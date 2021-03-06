<?php
require_once __DIR__ . '/vendor/autoload.php';
session_start();
date_default_timezone_set('Europe/Paris');

// Create and configure Slim app
$app = new \Slim\App(['settings' => [ 'addContentLengthHeader' => false, "displayErrorDetails" => ($_SERVER["SERVER_NAME"] != 'tifod.com')]]);
$container = $app->getContainer();

// personnal functions
function update_edit_id ($post_id){
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare ('SELECT id FROM post WHERE parent_id = :post_id AND is_an_edit = 1 AND user_id_pin != 0 ORDER BY score_percent DESC, score_result DESC, posted_on DESC LIMIT 1');
    $reponse->execute(['post_id' => $post_id]);
    $winning_edit = $reponse->fetch();
    $winning_edit = $winning_edit ? $winning_edit['id'] : $winning_edit;
    $reponse = $db->prepare('UPDATE post SET edit_id = :edit_id WHERE id = :post_id');
    $reponse->execute(['post_id' => $post_id, 'edit_id' => (empty($winning_edit) ? 0 : $winning_edit)]);
    $reponse->closeCursor();
}
function confidence_percent ($ups, $downs){
    // calcul basé sur https://medium.com/hacking-and-gonzo/how-reddit-ranking-algorithms-work-ef111e33d0d9
    // "Wilson score interval"
    $n = $ups + $downs;
    if ($n == 0) return 0;
    $z = 1.281551565545;
    $p = $ups / $n;
    $left = $p + 1/(2*$n)*$z*$z;
    $right = $z*sqrt($p*(1-$p)/$n + $z*$z/(4*$n*$n));
    $under = 1+1/$n*$z*$z;
    return (($left - $right) / $under) * 100;
}
function createTree($children_list, $children){
    $tree = array();
    foreach ($children as $child){
        if(isset($children_list[$child['id']])){
            $child['children'] = createTree($children_list, $children_list[$child['id']]);
        }
        $tree[] = $child;
    }
    return $tree;
}
function user_can_do ($action_name, $project_type) {
    $permissions = [
        'platform' => [
            'create_project' => ['regular_member', 'director', 'moderator'],
			'delete_user' => [ null ],
        ],
        'open_public' => [
            'edit_post' => ['regular_member', 'director', 'moderator'],
            'pin_edit_post' => ['post_owner', 'director', 'moderator'],
            'add_post' => ['regular_member', 'director', 'moderator'],
            'view_project' => ['not_a_member', 'regular_member', 'director', 'moderator'],
            'vote_post' => ['regular_member', 'director', 'moderator'],
            'reset_score_post' => [ null ],
            'delete_project' => ['director'],
            'delete_post' => ['director'],
            'pin_post' => ['director', 'moderator'],
        ],
        'closed_public' => [
            'edit_post' => ['regular_member', 'director', 'moderator'],
            'pin_edit_post' => ['post_owner', 'director', 'moderator'],
            'add_post' => ['director', 'moderator'],
            'view_project' => ['not_a_member', 'regular_member', 'director', 'moderator'],
            'vote_post' => ['regular_member', 'director', 'moderator'],
            'reset_score_post' => ['director'],
            'delete_project' => ['director'],
            'delete_post' => ['director'],
            'pin_post' => ['director', 'moderator'],
        ],
        'closed_private' => [
            'edit_post' => ['director', 'moderator'],
            'pin_edit_post' => ['post_owner', 'director', 'moderator'],
            'add_post' => ['director', 'moderator'],
            'view_project' => ['director', 'moderator'],
            'vote_post' => ['director', 'moderator'],
            'reset_score_post' => ['director'],
            'delete_project' => ['director'],
            'delete_post' => ['director'],
            'pin_post' => ['director', 'moderator'],
        ]
    ];
    
    $current_project_type = empty($project_type) ? 'platform' : $project_type;
    if (empty($permissions[$current_project_type][$action_name])){
        throw new Exception("Nom d'action inconnue (\"$action_name\", \"$current_project_type\")");
    }
    $p = $permissions[$current_project_type][$action_name];
    return (isset($_SESSION['current_user']) ? ($_SESSION['current_user']['platform_role'] == 'admin'? true : (isset($_SESSION['current_user']['current_project_role']) ? in_array('regular_member',$p) or in_array($_SESSION['current_user']['current_project_role'],$p) : in_array('regular_member',$p))) : in_array('not_a_member',$p));
}

// Register component on container
$container['view'] = function ($container) {
    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/src/templates');
    $twig = new \Twig\Environment($loader, [
        'cache' => (($container['settings']['displayErrorDetails']) ? false : __DIR__ . '/src/templates/twig_cache')
    ]);
    
    $twig->addGlobal('current_user', (empty($_SESSION['current_user']) ? null : $_SESSION['current_user']));
    
    $twig->addGlobal("current_url", $_SERVER["REQUEST_URI"]);
    
    $twig->addGlobal("server_name", $_SERVER["SERVER_NAME"]);
    
    $twig->addGlobal("dev_mode", $container['settings']['displayErrorDetails']);
    
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->query ('SELECT data_value FROM platform_data WHERE data_name = "version"');
    if ($site_version_tag = $reponse->fetch()){
        $twig->addGlobal("site_version_tag", $site_version_tag['data_value']);
    } else {
        $twig->addGlobal("site_version_tag", MyApp\Utility\Math::getARandomString(5));
    }
    $reponse->closeCursor();
    
    $filter = new \Twig\TwigFilter('break_attr', function ($input) {
        $rdm = MyApp\Utility\Math::getARandomString(3);
        $output = str_replace('=', $rdm, $input);
        $except = ['style', 'href', 'target'];
        foreach ($except as $e){
            $output = str_replace($e.$rdm, $e.'=', $output);
        }
        return $output;
    });
    $twig->addFilter($filter);
    
    $filter = new \Twig\TwigFilter('is_allowed_for', function ($action_name, $project_type) { return user_can_do($action_name, $project_type); });
    $twig->addFilter($filter);
    
    $filter = new \Twig\TwigFilter('markdown', function ($text) { return Parsedown::instance()->text($text); });
    $twig->addFilter($filter);
        
    $filter = new \Twig\TwigFilter('timeago', function ($datetime) {
      $time = time() - strtotime($datetime); 

      $units = array (
        31536000 => 'an',
        2592000 => 'mois',
        604800 => 'semaine',
        86400 => 'jour',
        3600 => 'heure',
        60 => 'minute',
        1 => 'seconde'
      );

      foreach ($units as $unit => $val) {
        if ($time < $unit) continue;
        $numberOfUnits = floor($time / $unit);
        return ($unit == 1)? "à l'instant" : 
               'il y a '.$numberOfUnits.' '.$val.(($numberOfUnits>1 and $val != 'mois') ? 's' : '');
      }
    });
    $twig->addFilter($filter);
    
    return $twig;
};

// Override the default Not Found Handler
$container['notFoundHandler'] = function ($c) {
    return function ($request, $response) use ($c) {
        return $c['response']
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/html')
            ->write($c['view']->render('error/404.html'));
    };
};
// Override the default php Error Handler
$container['phpErrorHandler'] = function ($c) {
    return function ($request, $response, $error) use ($c) {
        return $c['response']
            ->withStatus(500)
            ->withHeader('Content-Type', 'text/html')
            ->write($c['view']->render('error/error.html', ['title' => 'Erreur interne', 'error' => $error]));
    };
};
$container['errorHandler'] = function ($c) {
    return function ($request, $response, $error) use ($c) {
        return $c['response']
            ->withStatus(500)
            ->withHeader('Content-Type', 'text/html')
            ->write($c['view']->render('error/error.html', ['title' => 'Erreur interne', 'error' => $error]));
    };
};
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting, so ignore it
        return;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
// Define app routes
$app->get('/version', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->query ('SELECT data_value FROM platform_data WHERE data_name = "version"');
    if ($site_version_tag = $reponse->fetch()['data_value']){
        $reponse->closeCursor();
        return "{ \"messages\": [ {\"text\": \"On en est à la version $site_version_tag\"} ] }";
    } else {
        $reponse->closeCursor();
        return "beta version";
    }
});
$app->get('/get_last_posted_on/{project_id}/{last_time}', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare ('select * from post where project_id = :project_id AND posted_on > :last_time ORDER BY posted_on LIMIT 1');
    $reponse->execute([
        'project_id' => $args['project_id'],
        'last_time' => $args['last_time']
    ]);
    $donnees = $reponse->fetch();
    if ($donnees == false) die (json_encode($donnees));
    if ($donnees['is_an_edit'] == 1) die (json_encode(['post_data' => $donnees]));
    $post_id_searched = $donnees['id'];
    
    $edit_values_needed = ['content', 'content_type', 'posted_on', 'author_id'];
    $edit_query = ', (select avatar from user where user_id = (IF(p.edit_id = 0,NULL,(SELECT author_id FROM post tp WHERE tp.id = p.edit_id)))) edit_author_avatar, (select user_name from user where user_id = (IF(p.edit_id = 0,NULL,(SELECT author_id FROM post tp WHERE tp.id = p.edit_id)))) edit_author_name';
    foreach ($edit_values_needed as $edit_field){
        $edit_query .= ', (IF(p.edit_id = 0,NULL,(SELECT ' . $edit_field . ' FROM post tp WHERE tp.id = p.edit_id))) edit_' . $edit_field;
    }
    $reponse = $db->prepare ('select *, (SELECT IF (p.user_id_pin = 0,0,1)) has_pin, (SELECT project_type FROM project WHERE project_id = :project_id) project_type, (SELECT COUNT(id) FROM post tp WHERE tp.parent_id = p.parent_id AND tp.is_an_edit = 0) siblings_amount, (select user_name from user u where u.user_id = p.author_id) author_name, (select user_name from user u where u.user_id = p.user_id_pin) user_pseudo_pin, (select avatar from user u where u.user_id = p.author_id) author_avatar, (SELECT COUNT(*) FROM post tp WHERE tp.parent_id = p.id AND tp.is_an_edit = 1) edit_number' . $edit_query . ' from post p where project_id = :project_id order by has_pin desc, score_percent desc, score_result desc, posted_on desc');
    $reponse->execute([ 'project_id' => $args['project_id'] ]);
    $donnees = [];
    while ($donnees[] = $reponse->fetch());
    array_pop($donnees);

    // creating a comprehensive list of the projet posts
    $i = 0;
    $all_posts = [];
    foreach ($donnees as $k => $post){
        if ($post['is_an_edit'] == 0){
            $posts [$i] = $post;
            if ($post['parent_id'] == 0) $topPostId = $i;
            $i++;
        }
        if ($post['id'] == $post_id_searched) $last_post = $post;
        $all_posts[$post['id']] = $post;
    }
    $last_post['is_changing_parent'] = ($last_post['is_an_edit'] == 1 and $all_posts[$last_post['parent_id']]['posted_on'] == $last_post['posted_on']);

    $new = [];
    foreach ($posts as $a){
        $new[$a['parent_id']][] = [
            'innerHTML' => $this->view->render('post/tree-post.html', ['post' => $a]),
            'id' => $a['id']
        ];
        if ($a['parent_id'] == 0) $top_post_json = [
            'innerHTML' => $this->view->render('post/tree-post.html', ['post' => $a]),
            'id' => $a['id']
        ];
    }
    $project_json = createTree($new, [$top_post_json]);
    
    $reponse->closeCursor();
    
    if ($last_post['siblings_amount'] >= 3){
        $output = [
            'post_data' => $last_post,
            'html' => $this->view->render('/post/post-content.html', ['post' => $last_post, 'project_type' => $last_post['project_type']]),
            'html_link' => $this->view->render('/post/link-radio-button.html', ['post' => $last_post]),
            'html_menu' => $this->view->render('/post/post-more/post-more-menu.html', ['post' => $last_post, 'project_type' => $last_post['project_type']])
        ];
    } elseif ($last_post['siblings_amount'] == 1) {
        $output = [
            'post_data' => $last_post,
            'html' => '<div class="post-level active-level" id="' . $last_post['parent_id'] . '-children">' . $this->view->render('/post/branch.html', ['post' => ['children' => [$last_post]], 'project_type' => $last_post['project_type']]) . '</div>',
            'html_menu' => $this->view->render('/post/post-more/post-more-menu.html', ['post' => $last_post, 'project_type' => $last_post['project_type']])
        ];
    } else if ($last_post['siblings_amount'] == 2){
        $output = [
            'post_data' => $last_post,
            'html' => $this->view->render('/post/post-content.html', ['post' => $last_post, 'project_type' => $last_post['project_type']]),
            'html_link' => $this->view->render('/post/post_navbar.html', ['children' => [$last_post, $last_post]]),
            'html_menu' => $this->view->render('/post/post-more/post-more-menu.html', ['post' => $last_post, 'project_type' => $last_post['project_type']])
        ];
    }
    
    // $project_json
    $output['tree_structure'] = $this->view->createTemplate('{{ project_json.0|json_encode|raw }}')->render(['project_json'=> $project_json]);
    die(json_encode($output));
});
$app->get('/p/{projectId}', function ($request, $response, $args) {
    $projectId = $args['projectId'];
    
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->query ('select project_id from post where parent_id = 0');
    while ($donnees[] = $reponse->fetch());
    array_pop($donnees);
    $reponse->closeCursor();
    $projectsId = [];
    foreach ($donnees as $each_project){ $projectsId[] = $each_project['project_id']; }
    
    if (in_array($projectId,$projectsId)){
        $reponse = $db->prepare ('select project_type from project where project_id = :project_id');
        $reponse->execute([ 'project_id' => $projectId ]);
        $project_type = $reponse->fetch()['project_type'];
        
        if (!empty($_SESSION['current_user'])){
            $reponse = $db->prepare ('select project_role from project_role where project_id = :project_id and user_id = :user_id');
            $reponse->execute([
                'project_id' => $projectId,
                'user_id' => $_SESSION['current_user']['user_id']
            ]);
            $role = $reponse->fetch()['project_role'];
            $_SESSION['current_user']['current_project_role'] = empty($role) ? 'regular_member' : $role;
        }
        if (user_can_do('view_project',$project_type)){
            $edit_values_needed = ['content', 'content_type', 'posted_on', 'author_id'];
            $edit_query = ', (select avatar from user where user_id = (IF(p.edit_id = 0,NULL,(SELECT author_id FROM post tp WHERE tp.id = p.edit_id)))) edit_author_avatar, (select user_name from user where user_id = (IF(p.edit_id = 0,NULL,(SELECT author_id FROM post tp WHERE tp.id = p.edit_id)))) edit_author_name';
            foreach ($edit_values_needed as $edit_field){
                $edit_query .= ', (IF(p.edit_id = 0,NULL,(SELECT ' . $edit_field . ' FROM post tp WHERE tp.id = p.edit_id))) edit_' . $edit_field;
            }
            $reponse = $db->prepare ('select *, (SELECT IF (p.user_id_pin = 0,0,1)) has_pin, (select user_name from user u where u.user_id = p.author_id) author_name, (select user_name from user u where u.user_id = p.user_id_pin) user_pseudo_pin, (select avatar from user u where u.user_id = p.author_id) author_avatar, (SELECT COUNT(*) FROM post tp WHERE tp.parent_id = p.id AND tp.is_an_edit = 1) edit_number' . $edit_query . ' from post p where project_id = :project_id order by has_pin desc, score_percent desc, score_result desc, posted_on desc');
            $reponse->execute([ 'project_id' => $projectId ]);
            $donnees = [];
            while ($donnees[] = $reponse->fetch());
            array_pop($donnees);
            $reponse->closeCursor();

            if (count($donnees) == 1){
                return $this->view->render('post/project-player.html', ['top_post' => $donnees[0], 'project' => $donnees, 'project_type' => $project_type, 'projectId' => $projectId]);
            }

            // creating a comprehensive list of the projet posts
            $lastPostedOn = $donnees[0]['posted_on'];
            $i = 0;
            foreach ($donnees as $k => $post){
                if ($post['is_an_edit'] == 0){
                    $posts [] = $post;
                    if ($post['parent_id'] == 0) $topPostId = $i;
                    $i++;
                }
                if ($post['posted_on'] > $lastPostedOn) $lastPostedOn = $post['posted_on'];
            }
            
            $new = [];
            foreach ($posts as $a){
                $new[$a['parent_id']][] = $a;
            }
            $project = ['children' => createTree($new, [$posts[$topPostId]])];

            $new = [];
            foreach ($posts as $a){
                $new[$a['parent_id']][] = [
                    'innerHTML' => $this->view->render('post/tree-post.html', ['post' => $a]),
                    'id' => $a['id']
                ];
                if ($a['parent_id'] == 0) $top_post_json = [
                    'innerHTML' => $this->view->render('post/tree-post.html', ['post' => $a]),
                    'id' => $a['id']
                ];
            }
            $project_json = createTree($new, [$top_post_json]);
            
            return $this->view->render('post/project-player.html', ['top_post' => $project['children'][0],'project' => $project, 'project_type' => $project_type, 'projectId' => $projectId, 'project_json' => $project_json, 'last_posted_on' => $lastPostedOn]);
        } else {
            throw new Exception("Vous n'êtes pas autorisé à consulter ce projet");
        }
    } else {
		throw new Exception("Ce projet n'existe pas");
    }
});
$app->get('/', function ($request, $response) {
    if (isset($_SESSION['current_user'])){
        header('Location: /p'); exit();
    } else {
        return $this->view->render('homepage.html');
    }
});
$app->get('/about', function ($request, $response) {
    return $this->view->render('homepage.html');
});
$app->get('/project', function ($request, $response) { header('Location: /p'); exit(); });
$app->get('/projects', function ($request, $response) { header('Location: /p'); exit(); });
$app->get('/p', function ($request, $response) {
    $db = MyApp\Utility\Db::getPDO();
    $edit_values_needed = ['content', 'content_type'];
    $edit_query = ', (select avatar from user where user_id = (IF(p.edit_id = 0,NULL,(SELECT author_id FROM post tp WHERE tp.id = p.edit_id)))) edit_author_avatar, (select user_name from user where user_id = (IF(p.edit_id = 0,NULL,(SELECT author_id FROM post tp WHERE tp.id = p.edit_id)))) edit_author_name';
    foreach ($edit_values_needed as $edit_field){
        $edit_query .= ', (IF(p.edit_id = 0,NULL,(SELECT ' . $edit_field . ' FROM post tp WHERE tp.id = p.edit_id))) edit_' . $edit_field;
    }
    
    $reponse = $db->query ('select *, (SELECT IF (p.user_id_pin = 0,0,1)) has_pin, (SELECT COUNT(*) FROM post tp WHERE tp.project_id = p.project_id) post_count, (SELECT COUNT(DISTINCT author_id) FROM post tp WHERE tp.project_id = p.project_id) author_count, (select user_name from user u where u.user_id = p.author_id) author_name' . $edit_query . ' from post p where parent_id = 0 order by has_pin desc, score_percent desc, score_result desc, posted_on desc');
    while ($donnees[] = $reponse->fetch());
    array_pop($donnees);
    $max_id = 0;
    foreach($donnees as $post){
        $max_id = $post['project_id'] > $max_id ? $post['project_id'] : $max_id;
    }
    $reponse->closeCursor();
    return $this->view->render('post/all-project.html', ['projects' => $donnees, 'child' => ['id' => 0], 'projectId' => ($max_id + 1)]);
});
$app->post('/create-project', function ($request, $response) {
    if (user_can_do('create_project','platform')){
        if (!(empty($_POST['content']) or empty($_POST['project_id']))){
			$default_project_type = 'open_public';
            $db = MyApp\Utility\Db::getPDO();
            
            $reponse = $db->prepare ("INSERT INTO post(content, content_type, parent_id, project_id, path, author_id) VALUES (:content, 'text', 0, :project_id, '/', :author_id); UPDATE post SET path = CONCAT(path,(SELECT LAST_INSERT_ID()),'/') WHERE id = (SELECT LAST_INSERT_ID()); INSERT INTO project_role (project_id, user_id, project_role) VALUES (:project_id,:author_id,'director'); INSERT INTO project (project_id, project_type, project_root_post_id) VALUES (:project_id,:project_type,(SELECT LAST_INSERT_ID()));");
            $reponse->execute([
                'content' => $_POST['content'],
                'project_id' => $_POST['project_id'],
                'author_id' => $_SESSION['current_user']['user_id'],
                'project_type' => $default_project_type
            ]);
            $reponse->closeCursor();
        }
        header('Location: /p/' . $_POST['project_id']); exit();
    }
    header('Location: /login'); exit();
});
$app->post('/add-post', function ($request, $response) {
    if ((!empty($_POST['content']) or !empty($_POST['image']) or !empty($_FILES['image']['name'])) and isset($_POST['parent_id']) and isset($_POST['is_an_edit'])){
        $db = MyApp\Utility\Db::getPDO();
        $reponse = $db->prepare ('select project_type, (select parent_id from post where id = :parent_id) grand_parent_id, (SELECT is_an_edit FROM post WHERE id = :parent_id) is_an_edit, (SELECT COUNT(*) FROM post WHERE id = :parent_id) id_parent_id_valid, (SELECT project_id FROM post WHERE id = :parent_id) project_id, (SELECT auto_pin_edits FROM post WHERE id = :parent_id) auto_pin_edits, (SELECT author_id FROM post WHERE id = :parent_id) parent_author_id from project where project_id = (SELECT project_id FROM post WHERE id = :parent_id)');
        $reponse->execute([
			'parent_id' => $_POST['parent_id']
		]);
        $donnees = $reponse->fetch();
        if ($donnees['is_an_edit'] == 1) throw new Exception ('Impossible de modifier une modification');
        if ($donnees['grand_parent_id'] == 0 and empty($_POST['content']) and $_POST['is_an_edit'] == 'true') throw new Exception ("Seul du texte peut être proposé comme modification du titre du projet");
		$project_type = $donnees['project_type'];
		$project_id = $donnees['project_id'];
		$auto_pin_edits = $donnees['auto_pin_edits'];
		$parent_author_id = $donnees['parent_author_id'];
		if ($donnees['id_parent_id_valid'] == 0) throw new Exception ("Vous tentez de répondre à un post qui n'existe plus (il a été supprimé)");
        if (user_can_do('add_post',$project_type)){
            $reponse = $db->prepare ("INSERT INTO post(content, content_type, is_an_edit, parent_id, project_id, path, author_id) VALUES (:content, :content_type, :is_an_edit, :parent_id, :project_id, (IF (:parent_id = 0,'/',(SELECT path FROM post AS p WHERE id = :parent_id))), :author_id); UPDATE post SET path = CONCAT(path,(SELECT LAST_INSERT_ID()),'/')" . (($auto_pin_edits == '1' && $_POST['is_an_edit'] == 'true') ? ", user_id_pin = " . $parent_author_id : '') . " WHERE id = (SELECT LAST_INSERT_ID())");
            $reponse->execute([
                'content' => (empty($_POST['content']) ? 'file' : $_POST['content']),
                'content_type' => (empty($_POST['content']) ? 'file' : 'text'),
                'is_an_edit' => (($_POST['is_an_edit'] == 'true') ? 1 : 0),
                'parent_id' => $_POST['parent_id'],
                'project_id' => $project_id,
                'author_id' => $_SESSION['current_user']['user_id'],
                'parent_author_id' => $parent_author_id
            ]);
            $reponse->closeCursor();
			$reponse = $db->query('SELECT LAST_INSERT_ID()');
			$post_id = $reponse->fetch()[0];
            if (empty($_POST['content'])){
                // need to get infos about the post
				if (!empty($_POST['image'])){
                    $ext = "png";
                } else {
                    $allowed = ['gif', 'png' ,'jpg', 'jpeg', 'svg'];
                    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    if(!in_array($ext,$allowed)) { throw new Exception ("Format de fichier non supporté (formats acceptés : " . implode(", ",$allowed) . ")"); }
                }
                $fileName = MyApp\Utility\Math::getARandomString(6) . '-' . $post_id . '.' . $ext;
                
                $reponse->closeCursor();
                $reponse = $db->prepare("UPDATE post SET content = :fileName WHERE id = :post_id");
                $reponse->execute(['fileName' => $fileName, 'post_id' => $post_id]);
                $reponse->closeCursor();
                
                if (!empty($_POST['image'])){
                    $img = filter_input(INPUT_POST, 'image', FILTER_SANITIZE_URL);
                    $img = str_replace(' ', '+', str_replace('data:image/png;base64,', '', $img));
                    $data = base64_decode($img);
                    file_put_contents(__DIR__ . '/public/img/post/' . $fileName, $data);
                } else {
                    if (!move_uploaded_file($_FILES['image']['tmp_name'], __DIR__.'/public/img/post/'.$fileName)) {
                        echo '<pre>';
                        print_r($_FILES);
                        echo '</pre>';
                        throw new Exception ("Attaque potentielle par téléchargement de fichiers");
                    }
                }
            }
            if ($_POST['is_an_edit'] == 'true'){
                update_edit_id($_POST['parent_id']);
                header('Location: /edit/'.$_POST['parent_id'].'#'.$post_id);
            } else {
                header('Location: /p/'.$project_id.'#'.$post_id);
            }
            exit();
        } else {
            throw new Exception ("Vous n'êtes pas autorisés à poster dans ce projet, veuillez contacter le créateur de ce projet, ou bien vous adressez à l'équipe tifod si vous pensez que c'est un bug");
        }
    } else {
        throw new Exception ('Vous avez oublié de remplir certains champs ("content" ou "image", "parent_id", "is_an_edit")');
    }
});
$app->get('/edit/{post-id}', function ($request, $response, $args) {
	$db = MyApp\Utility\Db::getPDO();
	$reponse = $db->prepare ('select project_type, (SELECT is_an_edit FROM post WHERE id = :post_id) is_an_edit from project where project_id = (SELECT project_id FROM post WHERE id = :post_id)');
    $reponse->execute(['post_id' => $args['post-id']]);
    $donnees = $reponse->fetch();
    if ($donnees['is_an_edit'] == '1') throw new Exception ('Impossible de modifier une modification');
    $reponse = $db->prepare ('select *, (select user_name from user u where u.user_id = p.author_id) author_name, (select user_name from user u where u.user_id = p.user_id_pin) user_pseudo_pin, (select avatar from user u where u.user_id = p.author_id) author_avatar from post p where id = :post_id');
    $reponse->execute(['post_id' => $args['post-id']]);
    $parent_post = $reponse->fetch();
    if (isset($_SESSION['current_user']) and $parent_post['author_id'] == $_SESSION['current_user']['user_id']) $_SESSION['current_user']['current_project_role'] = 'post_owner';
    $reponse = $db->prepare ('select *, (SELECT IF (p.user_id_pin = 0,0,1)) has_pin, (select user_name from user u where u.user_id = p.author_id) author_name, (select user_name from user u where u.user_id = p.user_id_pin) user_pseudo_pin, (select avatar from user u where u.user_id = p.author_id) author_avatar from post p where parent_id = :post_id and is_an_edit = 1 order by has_pin desc, score_percent desc, score_result desc, posted_on desc');
    $reponse->execute(['post_id' => $args['post-id']]);
    while ($modifications[] = $reponse->fetch());
    array_pop($modifications);
    return $this->view->render('post/edit.html', ['parent_post' => $parent_post, 'modifications' => $modifications, 'project_type' => $donnees['project_type']]);
});
$app->get('/delete-post/{post-id}', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare ('select project_type from project where project_id = (SELECT project_id FROM post WHERE id = :post_id)');
    $reponse->execute(['post_id' => $args['post-id']]);
    $project_type = $reponse->fetch()['project_type'];
    if (user_can_do('delete_post',$project_type)){
        $reponse = $db->prepare("SELECT content, content_type, parent_id, is_an_edit FROM post WHERE id = :post_id");
        $reponse->execute([ 'post_id' => $args['post-id'] ]);
        $donnees = $reponse->fetch();
        if ($donnees['content_type'] == 'file' and file_exists(__DIR__ . '/public/img/post/'.$donnees['content'])) unlink(__DIR__ . '/public/img/post/'.$donnees['content']);
        
        $reponse = $db->prepare ("DELETE FROM post_vote WHERE post_vote.post_id IN (SELECT id FROM post WHERE path LIKE concat('%', (select * from (select path from post where id = :post_id) p), '%')); DELETE FROM post_vote WHERE post_id = :post_id; DELETE FROM post WHERE path LIKE concat('%', (select * from (select path from post where id = :post_id) p), '%'); DELETE FROM project_role WHERE project_id = (SELECT project_id FROM project WHERE project_root_post_id = :post_id); DELETE FROM project WHERE project_root_post_id = :post_id;");
        $reponse->execute([ 'post_id' => $args['post-id'] ]);
        $reponse->closeCursor();
        if ($donnees['is_an_edit']) update_edit_id($donnees['parent_id']);
    }
    header('Location: ' . (empty($_GET['redirect']) ? '/' : $_GET['redirect'])); exit();
});
$app->get('/vote/{vote-sign}/{post-id}', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare ('select project_type from project where project_id = (SELECT project_id FROM post WHERE id = :post_id)');
    $reponse->execute(['post_id' => $args['post-id']]);
    $project_type = $reponse->fetch()['project_type'];
    if (user_can_do('vote_post', $project_type)){
        // plus, minus
        if ($args['vote-sign'] == 'minus' or $args['vote-sign'] == 'plus'){
            $reponse = $db->prepare ('select is_upvote from post_vote where user_id = :user_id and post_id = :post_id');
            $reponse->execute([
                'user_id' => $_SESSION['current_user']['user_id'],
                'post_id' => $args['post-id']
            ]);
            while ($donnees[] = $reponse->fetch());
            
            if (!($donnees[0]['is_upvote'] === '1' and $args['vote-sign'] == 'plus') and !($donnees[0]['is_upvote'] === '0' and $args['vote-sign'] == 'minus')){
                if ($donnees[0] === false){
                    $t = ($args['vote-sign'] == 'minus') ? ['vote_minus', '-', '0'] : ['vote_plus', '+', '1'];
                    $query = 'update post set ' . $t[0] . ' = ' . $t[0] . ' + 1, score_result = score_result ' . $t[1] . ' 1 where id = :post_id; INSERT INTO post_vote (post_id, user_id, is_upvote) VALUES (:post_id, :user_id, ' . $t[2] . ');';
                } else if (($donnees[0]['is_upvote'] === '1' and $args['vote-sign'] == 'minus') or ($donnees[0]['is_upvote'] === '0' and $args['vote-sign'] == 'plus')) {
                    $t = ($args['vote-sign'] == 'minus') ? ['vote_plus', '-'] : ['vote_minus', '+'];
                    $query = 'delete FROM post_vote where post_id = :post_id and user_id = :user_id; update post set ' . $t[0] . ' = ' . $t[0] . ' - 1, score_result = score_result ' . $t[1] . ' 1 where id = :post_id;';
                }
                
                $reponse = $db->prepare ($query);
                $reponse->execute([
                    'post_id' => $args['post-id'],
                    'user_id' => $_SESSION['current_user']['user_id']
                ]);
                
                $reponse = $db->prepare('SELECT vote_plus, vote_minus, parent_id FROM post WHERE id = :post_id');
                $reponse->execute([ 'post_id' => $args['post-id'] ]);
                $donnees = $reponse->fetch();
                
                $confidence = confidence_percent($donnees['vote_plus'], $donnees['vote_minus']);
                
                $reponse = $db->prepare('update post set score_percent = :confidence where id = :post_id');
                $reponse->execute([
                    'confidence' => $confidence,
                    'post_id' => $args['post-id']
                ]);
            }
            
            $reponse = $db->prepare ('select score_percent, score_result, vote_minus, vote_plus FROM post where id = :post_id');
            $reponse->execute(['post_id' => $args['post-id']]);
            update_edit_id($donnees['parent_id']);
            $donnees = [];
            while ($donnees[] = $reponse->fetch());
            $reponse->closeCursor();
            die(json_encode($donnees));
        } else {
            die('Url invalide');
        }
    }
});
$app->get('/toggleAutoPin/{post-id}', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
	$reponse = $db->prepare ('select project_type from project where project_id = (SELECT project_id FROM post WHERE id = :post_id)');
    $reponse->execute(['post_id' => $args['post-id']]);
    $project_type = $reponse->fetch()['project_type'];
    $reponse = $db->prepare ('select author_id from post where id = :post_id');
    $reponse->execute(['post_id' => $args['post-id']]);
    $donnees = $reponse->fetch();
    if (isset($_SESSION['current_user']) and $donnees['author_id'] == $_SESSION['current_user']['user_id']) $_SESSION['current_user']['current_project_role'] = 'post_owner';
    if (user_can_do('pin_edit_post',$project_type)){
        $reponse = $db->prepare ('SELECT auto_pin_edits FROM post where id = :post_id');
        $reponse->execute([ 'post_id' => $args['post-id'] ]);
        if ($reponse->fetch()['auto_pin_edits'] == 0){
            $reponse = $db->prepare ('UPDATE post SET auto_pin_edits = 1 where id = :post_id; UPDATE post SET user_id_pin = :author_id WHERE parent_id = :post_id AND is_an_edit = 1;');
            $reponse->execute([
                'post_id' => $args['post-id'],
                'author_id' => $_SESSION['current_user']['user_id']
            ]);
        } else {
            $reponse = $db->prepare ('UPDATE post SET auto_pin_edits = 0 where id = :post_id; UPDATE post SET user_id_pin = 0 WHERE parent_id = :post_id AND is_an_edit = 1;');
            $reponse->execute([ 'post_id' => $args['post-id'] ]);
        }
        $reponse->closeCursor();
        update_edit_id($args['post-id']);
        header('Location: ' . (empty($_GET['redirect']) ? '/' : $_GET['redirect'])); exit();
    }
});
$app->get('/togglePin/{post-id}', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare ('select project_type, (SELECT is_an_edit FROM post WHERE id = :post_id) is_an_edit, (SELECT author_id FROM post WHERE id = :post_id) post_author_id, (SELECT parent_id FROM post WHERE id = :post_id) parent_id from project where project_id = (SELECT project_id FROM post WHERE id = :post_id)');
    $reponse->execute(['post_id' => $args['post-id']]);
    $donnees = $reponse->fetch();
    if (isset($_SESSION['current_user']) and $donnees['post_author_id'] == $_SESSION['current_user']['user_id']) $_SESSION['current_user']['project_role'] = 'post_owner';
    if ((user_can_do('pin_post',$donnees['project_type']) and $donnees['is_an_edit'] == '0') or (user_can_do('pin_edit_post',$donnees['project_type']) and $donnees['is_an_edit'] == '1')){
        $reponse = $db->prepare ('UPDATE post SET user_id_pin = (IF (user_id_pin = 0,:user_id_pin,0)) where id = :post_id');
        $reponse->execute([
            'user_id_pin' => $_SESSION['current_user']['user_id'],
            'post_id' => $args['post-id']
        ]);
        $reponse->closeCursor();
        if ($donnees['is_an_edit'] == '1') update_edit_id($donnees['parent_id']);
    }
    header('Location: ' . (empty($_GET['redirect']) ? '/' : $_GET['redirect'].'#'.$args['post-id'])); exit();
});
$app->get('/resetPostScore/{post-id}', function ($request, $response, $args) {
	$db = MyApp\Utility\Db::getPDO();
	$reponse = $db->prepare ('select project_type from project where project_id = (SELECT project_id FROM post WHERE id = :post_id)');
    $reponse->execute(['post_id' => $args['post-id']]);
    $project_type = $reponse->fetch()['project_type'];
    if (user_can_do('reset_score_post',$project_type)){
		$reponse = $db->prepare ('update post set score_percent = 0, score_result = 0, vote_minus = 0, vote_plus = 0 where id = :post_id; delete from post_vote where post_id = :post_id;');
        $reponse->execute(['post_id' => $args['post-id']]);
        update_edit_id($args['post-id']);
        header('Location: ' . (empty($_GET['redirect']) ? '/' : $_GET['redirect']));
    }
	$reponse->closeCursor();
	exit();
});
$app->get('/logout', function ($request, $response, $args) {
    session_destroy(); header('Location: ' . (empty($_GET['redirect']) ? '/' : $_GET['redirect'])); exit();
});
$app->get('/login', function ($request, $response, $args) {
    if (empty($_SESSION['current_user'])){
        return $this->view->render('connexion/login.html', ['signup_redirect' => (empty($_GET['signup_redirect'])? false : true),'email' => (empty($_GET['email'])? false : $_GET['email']), 'redirect_to' => (empty($_GET['redirect']) ? '/' : $_GET['redirect'])]);
    } else {
        header('Location: /'); exit();
    }
});
$app->post('/login', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare('select * from user where email = :email');
    $reponse->execute(['email' => $_POST['email']]);
    while ($donnees[] = $reponse->fetch());
    $reponse->closeCursor();
    
    if (empty($donnees[0]['email'])){
        throw new Exception("Email incorrect");
    } else {
        if(password_verify($_POST['password'], $donnees[0]['user_password'])) {
            $_SESSION['current_user'] = [
                'user_id' => $donnees[0]['user_id'],
                'pseudo' => $donnees[0]['user_name'],
                'email' => $donnees[0]['email'],
                'avatar' => $donnees[0]['avatar'],
                'description' => $donnees[0]['description'],
                'platform_role' => $donnees[0]['platform_role']
            ];
        } else {
            throw new Exception('Mot de passe incorrect');
        }
    }
    
    header('Location: ' . (empty($_GET['redirect']) ? '/' : $_GET['redirect'])); exit();
});
$app->get('/user', function ($request, $response, $args) { header('Location: /u'); exit(); });
$app->get('/users', function ($request, $response, $args) { header('Location: /u'); exit(); });
$app->get('/u', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->query("SELECT user_name, avatar, platform_role, user_id, (SELECT COUNT(id) FROM post AS p WHERE p.author_id = u.user_id) post_amount FROM user AS u ORDER BY user_id");
    while ($donnees[] = $reponse->fetch());
    array_pop($donnees);
    $reponse->closeCursor();
    return $this->view->render('user/user_list.html', ['users' => $donnees]);
});
$app->get('/u/{user_id}', function ($request, $response, $args) {
    $db = MyApp\Utility\Db::getPDO();
    $reponse = $db->prepare("SELECT * FROM user WHERE user_id = :user_id");
    $reponse->execute(['user_id' => $args['user_id']]);
    while ($donnees[] = $reponse->fetch());
    $reponse = $db->prepare("SELECT *, (SELECT content FROM post WHERE id = (SELECT project_root_post_id FROM project WHERE project.project_id = p.project_id)) AS project_name FROM post AS p WHERE author_id = :user_id ORDER BY posted_on DESC");
    $reponse->execute(['user_id' => $args['user_id']]);
    while ($posts[] = $reponse->fetch());
    array_pop($posts);
    $reponse->closeCursor();
    
    if (empty($donnees[0])){
        throw new Exception("Utilisateur inconnu");
    } else {
        $user = [
            'user_id' => $args['user_id'],
            'pseudo' => $donnees[0]['user_name'],
            'avatar' => $donnees[0]['avatar'],
            'platform_role' => $donnees[0]['platform_role'],
            'description' => $donnees[0]['description'],
            'posts' => $posts
        ];
        return $this->view->render('user/profile.html', ['user' => $user]);
    }
});
$app->get('/signup', function ($request, $response, $args) {
    if (empty($_SESSION['current_user'])){
        if (!empty($_GET['email'])){
            $db = MyApp\Utility\Db::getPDO();
            // check if user already exists
            $reponse = $db->prepare("SELECT user_id FROM user WHERE email = :email");
            $reponse->execute(['email' => $_GET['email']]);
            if ($reponse->fetch() !== false){ header('Location: /login?signup_redirect=1&email='.$_GET['email']); exit(); }
            
            $token_key = md5(MyApp\Utility\Math::getARandomString().$_GET['email']);
            // insert in DB and delete all expired entries
            $reponse = $db->prepare("DELETE FROM token WHERE email = :email; INSERT INTO token (action, token_key, expiration_date, email) VALUES ('signup', :token_key, :expiration_date, :email); DELETE FROM token WHERE expiration_date < NOW();");
            $reponse->execute([
                'token_key' => $token_key,
                'expiration_date' => date("Y-m-d H:i:s",strtotime('+1 day')),
                'email' => $_GET['email']
            ]);
            $reponse->closeCursor();
            
            // send email with the token
            try {
				mail($_GET['email'], 'Valider la création de votre compte Tifod', $this->view->render('email/signup.html',['token_key' => $token_key]), "MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\nFrom: Tifod <contact@tifod.com>\r\nReply-To: Tifod <contact@tifod.com>");
			} catch (Throwable $e) {
				$_GET['email'] .= ' ou <a href="/token/'.$token_key.'">cliquez ici</a>';
			}
            
            return $this->view->render('connexion/signup.html',['token_sent' => true, 'email' => $_GET['email']]);
        } else {
            return $this->view->render('connexion/signup.html');
        }
    } else {
        header('Location: /'); exit();
    }
});
$app->get('/password_reset', function ($request, $response, $args) {
    if (empty($_SESSION['current_user'])){
        if (!empty($_GET['email'])){
            // create token
            $token_key = md5(MyApp\Utility\Math::getARandomString().$_GET['email']);
            
            $db = MyApp\Utility\Db::getPDO();
            // check if user already exists
            $reponse = $db->prepare("SELECT user_id FROM user WHERE email = :email");
            $reponse->execute(['email' => $_GET['email']]);
            if ($reponse->fetch() === false){ header('Location: /signup?email='.$_GET['email']); exit(); }
            
            // insert token in DB and delete all expired entries
            $reponse = $db->prepare("DELETE FROM token WHERE email = :email; INSERT INTO token (action, token_key, expiration_date, email) VALUES ('password_reset', :token_key, :expiration_date, :email); DELETE FROM token WHERE expiration_date < NOW();");
            $reponse->execute([
                'token_key' => $token_key,
                'expiration_date' => date("Y-m-d H:i:s",strtotime('+1 hour')),
                'email' => $_GET['email']
            ]);
            $reponse->closeCursor();
            
            // send email with the token
			try {
				mail($_GET['email'], 'Réinitialiser votre mot de passe sur Tifod', $this->view->render('email/password_reset.html',['token_key' => $token_key]), "MIME-Version: 1.0\r\nContent-type: text/html; charset=utf-8\r\nFrom: Tifod <contact@tifod.com>\r\nReply-To: Tifod <contact@tifod.com>");
			} catch (Throwable $e) {
				$_GET['email'] .= ' ou <a href="/token/'.$token_key.'">cliquez ici</a>';
			}
            
            return $this->view->render('connexion/password_reset.html',['token_sent' => true, 'email' => $_GET['email']]);
        } else {
            return $this->view->render('connexion/password_reset.html');
        }
    } else {
        header('Location: /'); exit();
    }
});
$app->get('/token/{token_key}', function ($request, $response, $args) {
    // delete expired token_keys, then get token_key data
    $db = MyApp\Utility\Db::getPDO();
    $db->query('DELETE FROM token WHERE expiration_date < NOW();');
    $reponse = $db->prepare("SELECT email, action FROM token WHERE token_key = :token_key");
    $reponse->execute(['token_key' => $args['token_key']]);
    $donnees = $reponse->fetch();
    if ($donnees !== false){
        $email = $donnees['email'];
        $action = $donnees['action'];
        if ($action == 'signup'){
            // query to test
            $reponse = $db->prepare("INSERT INTO user (email) VALUES (:email); UPDATE user SET user_name = CONCAT('user-',(SELECT LAST_INSERT_ID())) WHERE email = :email;");
			$reponse->execute(['email' => $email]);
        }
        $reponse = $db->prepare("DELETE FROM token WHERE email = :email");
        $reponse->execute(['email' => $email]);
        $reponse = $db->prepare('SELECT * FROM user WHERE email = :email');
        $reponse->execute(['email' => $email]);
        $donnees = [];
        while ($donnees[] = $reponse->fetch());
        $_SESSION['current_user'] = [
            'user_id' => $donnees[0]['user_id'],
            'pseudo' => $donnees[0]['user_name'],
            'email' => $donnees[0]['email'],
            'avatar' => $donnees[0]['avatar'],
            'platform_role' => $donnees[0]['platform_role']
        ];
        $reponse->closeCursor();
        header('Location: /settings/new_password'); exit();
    } else {
        $reponse->closeCursor();
        throw new Exception ("Ce lien a expiré et n'est plus utilisable");
    }
});
$app->get('/settings', function ($request, $response, $args) {
    if (empty($_SESSION['current_user'])){
        header('Location: /login?redirect=/settings');
        exit();
    } else {
        return $this->view->render('user/settings.html');
    }
});
$app->get('/settings/new_password', function ($request, $response, $args) {
    return $this->view->render('user/new_password.html');
});
$app->post('/settings', function ($request, $response, $args) {
    if (empty($_POST['action'])){
        throw new Exception("Vous ne pouvez pas envoyer un formulaire vide");
    } else {
        if ($_POST['action'] == 'new_password'){
            $action = 'user_password';
            $new_value = password_hash($_POST['new_value'], PASSWORD_BCRYPT, ['cost' => 12]);
        } elseif ($_POST['action'] == 'new_user_name'){
            if (empty($_POST['new_value'])) throw new Exception ("Vous ne pouvez pas avoir de pseudo vide");
            $action = 'user_name';
            $new_value = $_POST['new_value'];
        } elseif ($_POST['action'] == 'new_description'){
            $action = 'description';
            $new_value = $_POST['new_value'];
        } elseif ($_POST['action'] == 'new_avatar'){
            $action = 'avatar';
            $file_name = $_SESSION['current_user']['user_id'] . '-' . $_FILES["new_value"]["name"];
            if (!move_uploaded_file($_FILES["new_value"]["tmp_name"], __DIR__ . '/public/img/user/' . basename($file_name))) throw new Exception("Erreur d'upload du fichier!");
            
            $new_value = $file_name;
            if ($_SESSION['current_user']['avatar'] != 'default.png' and file_exists(__DIR__ . '/public/img/user/' . $_SESSION['current_user']['avatar'])) unlink(__DIR__ . '/public/img/user/' . $_SESSION['current_user']['avatar']);
        }
        $db = MyApp\Utility\Db::getPDO();
        $reponse = $db->prepare("UPDATE user SET $action = :new_value WHERE user_id = :current_user_id");
        $reponse->execute([
            'new_value' => $new_value,
            'current_user_id' => $_SESSION['current_user']['user_id']
        ]);
        $reponse->closeCursor();
        switch ($_POST['action']){
            case 'new_user_name' : 
                $_SESSION['current_user']['pseudo'] = $new_value;
                break;
            case 'new_description' :
                $_SESSION['current_user']['description'] = $new_value;
                break;
            case 'new_avatar':
                $_SESSION['current_user']['avatar'] = $file_name;
                break;
        }
        header ('Location: /settings'); exit();
    }
});
$app->get('/delete-user/{user-id}', function ($request, $response, $args) {
	if (user_can_do('delete_user','platform') and $args['user-id'] != $_SESSION['current_user']['user_id']){
		$db = MyApp\Utility\Db::getPDO();
		$reponse = $db->prepare("SELECT avatar, (SELECT COUNT(*) FROM post WHERE author_id = :user_id) AS post_count FROM user WHERE user_id = :user_id");
		$reponse->execute([ 'user_id' => $args['user-id'] ]);
		$donnees = $reponse->fetch();
		if (!empty($donnees)){
			if ($donnees['post_count'] > 0) throw new Exception ("Vous ne pouvez pas supprimer ce compte car les posts de cet auteur existent encore!");
			if ($donnees['avatar'] != 'default.png' and file_exists(__DIR__ . '/public/img/user/' . $donnees['avatar'])) unlink(__DIR__ . '/public/img/user/' . $donnees['avatar']);
			$reponse = $db->prepare("DELETE FROM user WHERE user_id = :user_id; DELETE FROM post_vote WHERE user_id = :user_id; DELETE FROM project_role WHERE user_id = :user_id");
			$reponse->execute([ 'user_id' => $args['user-id'] ]);
			$reponse->closeCursor();
			header ('Location: /u'); exit();
		} else {
			$reponse->closeCursor();
			throw new Exception ("Cet utilisateur n'existe pas");
		}
	} else {
		throw new Exception ("Vous n'avez pas les droits nécessaires pour supprimer ce compte utilisateur");
	}
});
// Run app
$app->run();