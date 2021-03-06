<?php
/**
 * Leafpub: Simple, beautiful publishing. (https://leafpub.org)
 *
 * @link      https://github.com/Leafpub/leafpub
 * @copyright Copyright (c) 2016 Leafpub Team
 * @license   https://github.com/Leafpub/leafpub/blob/master/LICENSE.md (GPL License)
 */
namespace Leafpub\Controller;

use Leafpub\Admin,
    Leafpub\Backup,
    Leafpub\Blog,
    Leafpub\Cache,
    Leafpub\Error,
    Leafpub\Feed,
    Leafpub\History,
    Leafpub\Language,
    Leafpub\Post,
    Leafpub\Leafpub,
    Leafpub\Renderer,
    Leafpub\Search,
    Leafpub\Session,
    Leafpub\Setting,
    Leafpub\Tag,
    Leafpub\Theme,
    Leafpub\Upload,
    Leafpub\User,
    Leafpub\Importer;

/**
* APIController
*
* This class handles all ajax requests from the Leafpub backend.
* It's the controller for API endpoints
*
* @package Leafpub\Controller
**/
class APIController extends Controller {

    /**
    * Handles POST api/login
    *
    * @param \Slim\Http\Request $request 
    * @param \Slim\Http\Response $response 
    * @param array $args 
    * @return \Slim\Http\Response (json)
    *
    **/
    public function login($request, $response, $args) {
        $params = $request->getParams();

        if(Session::login($params['username'], $params['password'])) {
            return $response->withJson([
                'success' => true
            ]);
        } else {
            return $response->withJson([
                'success' => false,
                'invalid' => ['username', 'password'],
                'message' => Language::term('invalid_username_or_password')
            ]);
        }
    }

    /**
    * Handles POST api/login/recover
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function recover($request, $response, $args) {
        $params = $request->getParams();

        // Get the user
        $user = User::get($params['username']);
        if(!$user) {
            return $response->withJson([
                'success' => false,
                'invalid' => ['username'],
                'message' => Language::term('invalid_username')
            ]);
        }

        // Generate and set a password reset token
        User::update($user['slug'], [
            'reset_token' => $token = Leafpub::randomBytes(50)
        ]);

        // Send the user an email
        Leafpub::sendEmail([
            'to' => $user['email'],
            'subject' => '[' . Setting::get('title') . '] ' . Language::term('password_reset'),
            'message' =>
                Language::term('a_password_reset_has_been_requested_for_this_account') . "\n\n" .
                $user['name'] . ' — ' . $user['slug'] . "\n\n" .
                Language::term('if_this_was_sent_in_error_you_can_ignore_this_message') . "\n\n" .
                Language::term('to_reset_your_password_visit_this_address') . ' ' .
                Admin::url('login/reset/?username=' . rawurlencode($user['slug']) .
                '&token=' . rawurlencode($token)),
            'from' => 'Leafpub <leafpub@' . $_SERVER['HTTP_HOST'] . '>'
        ]);

        // Send response
        return $response->withJson([
            'success' => true,
            'message' => Language::term('check_your_email_for_further_instructions')
        ]);
    }

    /**
    * Handles POST api/login/reset
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function reset($request, $response, $args) {
        $params = $request->getParams();

        // Get the user
        $user = User::get($params['username']);
        if(!$user) {
            return $response->withJson([
                'success' => false,
                'message' => Language::term('invalid_username')
            ]);
        }

        // Tokens must match and cannot be empty
        if(empty($params['token']) || $params['token'] !== $user['reset_token']) {
            return $response->withJson([
                'success' => false,
                'message' => Language::term('invalid_or_expired_token')
            ]);
        }

        // New passwords must match
        if($params['password'] !== $params['verify-password']) {
            return $response->withJson([
                'status' => 'error',
                'invalid' => ['password', 'verify-password'],
                'message' => Language::term('the_passwords_you_entered_do_not_match')
            ]);
        }

        // Change password and remove token
        try {
            User::update($user['slug'], [
                'password' => $params['password'],
                'reset_token' => ''
            ]);
        } catch(Exception $e) {
            return $response->withJson([
                'success' => false,
                'invalid' => ['password'],
                'message' => Language::term('the_password_you_entered_is_too_short')
            ]);
        }

        // Log the user in for convenience
        Session::login($user['slug'], $params['password']);

        // Send response
        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles GET api/posts
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getPosts($request, $response, $args) {
        $params = $request->getParams();

        // Get posts
        $posts = Post::getMany([
            // If you're not an owner, admin, or editor then you can only see your own posts
            'author' => Session::isRole(['owner', 'admin', 'editor']) ? null : Session::user('slug'),
            'status' => null,
            'ignore_featured' => false,
            'ignore_pages' => false,
            'ignore_sticky' => false,
            'items_per_page' => 50,
            'end_date' => null,
            'page' => (int) $params['page'],
            'query' => empty($params['query']) ? null : $params['query']
        ], $pagination);

        // Render post list
        $html = Admin::render('partials/post-list', [
            'posts' => $posts
        ]);

        // Send response
        return $response->withJson([
            'success' => true,
            'html' => $html,
            'pagination' => $pagination
        ]);
    }

    /**
    * Handles adding and updating of a post
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    private function addUpdatePost($action, $request, $response, $args) {
        $params = $request->getParams();
        $properties = $params['properties'];
        $slug = $action === 'add' ? $properties['slug'] : $args['slug'];

        // If you're not an owner, admin, or editor then you can only add/update your own posts
        if(
            !Session::isRole(['owner', 'admin', 'editor']) &&
            $properties['author'] != Session::user('slug')
        ) {
            return $response->withStatus(403);
        }

        // To edit a post, you must be an owner, admin, editor or the owner of the post
        if(
            $action === 'update' &&
            !Session::isRole(['owner', 'admin', 'editor']) &&
            Post::get($params['post'])['author'] !== Session::user('slug')
        ) {
            return $response->withStatus(403);
        }

        // To feature a post, turn it into a page, or make it sticky you must be owner, admin, or
        // editor
        if(!Session::isRole(['owner', 'admin', 'editor'])) {
            unset($properties['featured']);
            unset($properties['page']);
            unset($properties['sticky']);
        }

        // Create tags that don't exist yet
        if(Session::isRole(['owner', 'admin', 'editor'])) {
            foreach((array) $properties['tag_data'] as $tag) {
                if(!Tag::exists($tag['slug'])) {
                    Tag::add($tag['slug'], [
                        'name' => $tag['name']
                    ]);
                }
            }
        }

        // Update the post
        try {
            if($action === 'add') {
                Post::add($slug, $properties);
            } else {
                Post::update($slug, $properties);
            }
        } catch(\Exception $e) {
            switch($e->getCode()) {
                case Post::INVALID_SLUG:
                    $message = Language::term('the_slug_you_entered_cannot_be_used');
                    $invalid = ['slug'];
                    break;
                case Post::ALREADY_EXISTS:
                    $message = Language::term('a_post_already_exists_at_the_url_you_entered');
                    $invalid = ['slug'];
                    break;
                case Post::INVALID_USER:
                    $message = Language::term('invalid_username');
                    $invalid = ['author'];
                    break;
                default:
                    $message = $e->getMessage();
            }
            return $response->withJson([
                'success' => false,
                'message' => $message,
                'invalid' => $invalid
            ]);
        }

        // Send response
        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles POSTS api/posts
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function addPost($request, $response, $args) {
        return $this->addUpdatePost('add', $request, $response, $args);
    }

    /**
    * Handles PUT api/posts/{slug}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function updatePost($request, $response, $args) {
        return $this->addUpdatePost('update', $request, $response, $args);
    }

    /**
    * Handles DELETE api/posts/{slug}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function deletePost($request, $response, $args) {

        // If you're not an owner, admin, or editor then you can only delete your own posts
        if(
            Session::isRole(['owner', 'admin', 'editor']) ||
            Post::get($args['slug'])['author'] === Session::user('slug')
        ) {
            return $response->withJson([
                'success' => Post::delete($args['slug'])
            ]);
        }

        return $response->withJson([
            'success' => false
        ]);
    }

    /**
    * Handles GET/POST api/posts/render
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function renderPost($request, $response, $args) {
        //
        // Render an editable post for the editor. This method supports GET and POST due to query
        // string size limitations.
        //
        //  Post data can be submitted one of three ways:
        //
        //      $request['post'] = 'post-slug'
        //      $request['post'] = ['title' => $title, 'content' => $content, ...]
        //      $request['post-json'] = '<json string>';
        //
        // Tags should be posted as `tags`:
        //
        //      ['favorites', 'life', 'love']
        //
        // Tag data should be passed in as `tag_data`. This data will be used to render previews for tags
        // that haven't been created yet.
        //
        //      [
        //          ['slug' => 'tag-1', 'name' => 'Tag 1'],
        //          ['slug' => 'tag-2', 'name' => 'Tag 2'],
        //          ...
        //      ]
        //
        $params = $request->getParams();

        // Generate post data
        if(isset($params['post-json'])) {
            // Post was passed as JSON data
            $post = json_decode($params['post-json'], true);
        } else {
            if(isset($params['new'])) {
                // New post
                $post = [
                    'slug' => ':new',
                    'title' => Setting::get('default_title'),
                    'content' => Leafpub::markdownToHtml(Setting::get('default_content')),
                    'author' => Session::user(),
                    'pub_date' => date('Y-m-d H:i:s')
                ];
            } else {
                // Existing post
                $post = $params['post'];
            }
        }

        // Render it
        try {
            $html = Post::render($post, [
                'editable' => true,
                'preview' => true,
                'zen' => $params['zen'] === 'true'
            ]);
        } catch(\Exception $e) {
            return $response
                ->withStatus(500)
                ->write(
                    Error::system([
                        'title' => 'Application Error',
                        'message' => $e->getMessage()
                    ])
                );
        }

        return $response
            // Prevents `Refused to execute a JavaScript script` error
            ->withAddedHeader('X-XSS-Protection', '0')
            ->write($html);
    }

    /**
    * Handles DELETE api/history{id} 
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function deleteHistory($request, $response, $args) {
        // Get the history item and the affected post so we can verify privileges
        $history = History::get($args['id']);
        $post = Post::get($history['slug']);
        if(!$history || !$post) {
            return $response->withJson([
                'success' => false
            ]);
        }

        // If you're not an owner, admin, or editor then you can only delete history that belongs to
        // your own own post
        if(
            Session::isRole(['owner', 'admin', 'editor']) ||
            $post['author'] === Session::user('slug')
        ) {
            return $response->withJson([
                'success' => History::delete($args['id'])
            ]);
        }

        return $response->withJson([
            'success' => false
        ]);
    }

    /**
    * Handles GET api/history/{id}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getHistory($request, $response, $args) {
        $history = History::get($args['id']);
        if(!$history) {
            return $response->withJson([
                'success' => false
            ]);
        }

        // Provide a neatly formed pub_date and pub_time for the editor
        $history['post_data']['pub_time'] =
            Leafpub::strftime('%H:%M', strtotime($history['post_data']['pub_date']));

        $history['post_data']['pub_date'] =
            Leafpub::strftime('%d %b %Y', strtotime($history['post_data']['pub_date']));

        // Return the requested history item
        return $response->withJson([
            'success' => true,
            'history' => $history
        ]);
    }

    /**
    * Handles GET api/tags
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getTags($request, $response, $args) {
        $params = $request->getParams();

        // To view tags, you must be an owner, admin, or editor
        if(!Session::isRole(['owner', 'admin', 'editor'])) {
            return $response->with(403);
        }

        // Get tags
        $tags = Tag::getMany([
            'items_per_page' => 50,
            'page' => (int) $params['page'],
            'query' => empty($params['query']) ? null : $params['query']
        ], $pagination);

        // Render tag list
        $html = Admin::render('partials/tag-list', [
            'tags' => $tags
        ]);

        // Send response
        return $response->withJson([
            'success' => true,
            'html' => $html,
            'pagination' => $pagination
        ]);
    }

    /**
    * Private method to handle add and update
    *
    * @param String $action
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    private function addUpdateTag($action, $request, $response, $args) {
        $params = $request->getParams();
        $slug = $action === 'add' ? $params['slug'] : $args['slug'];

        // To add/update tags, you must be an owner, admin, or editor
        if(!Session::isRole(['owner', 'admin', 'editor'])) {
            return $response->with(403);
        }

        // Create tag array
        $tag = [
            'slug' => $slug,
            'name' => $params['name'],
            'description' => $params['description'],
            'meta_title' => $params['meta-title'],
            'meta_description' => $params['meta-description'],
            'cover' => $params['cover'],
        ];

        // Add/update the tag
        try {
            if($action === 'add') {
                Tag::add($slug, $tag);
            } else {
                Tag::update($slug, $tag);
            }
        } catch(\Exception $e) {
            // Handle errors
            switch($e->getCode()) {
                case Tag::INVALID_SLUG:
                    $invalid = ['slug'];
                    $message = Language::term('the_slug_you_entered_cannot_be_used');
                    break;
                case Tag::ALREADY_EXISTS:
                    $invalid = ['slug'];
                    $message = Language::term('a_tag_already_exists_at_the_slug_you_entered');
                    break;
                case Tag::INVALID_NAME:
                    $invalid = ['name'];
                    $message = Language::term('the_name_you_entered_cannot_be_used');
                    break;
                default:
                    $message = $e->getMessage();
            }

            return $response->withJson([
                'success' => false,
                'invalid' => $invalid,
                'message' => $message
            ]);
        }

        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles POST api/tags
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function addTag($request, $response, $args) {
        return $this->addUpdateTag('add', $request, $response, $args);
    }

    /**
    * Handles PUT  api/tags/{slug}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function updateTag($request, $response, $args) {
        return $this->addUpdateTag('update', $request, $response, $args);
    }

    /**
    * Handles DELETE api/tags/{slug}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function deleteTag($request, $response, $args) {
        // To delete tags, you must be an owner, admin, or editor
        if(!Session::isRole(['owner', 'admin', 'editor'])) {
            return $response->with(403);
        }

        return $response->withJson([
            'success' => Tag::delete($args['slug'])
        ]);
    }

    /**
    * Handles PUT api/navigation
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function updateNavigation($request, $response, $args) {
        $params = $request->getParams();

        // To update navigation, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        // Create navigation array
        $navigation = [];
        $labels = (array) $params['label'];
        $links = (array) $params['link'];
        for($i = 0; $i < count($labels); $i++) {
            // Ignore items with empty labels
            if(mb_strlen($labels[$i])) {
                $navigation[] = [
                    'label' => $labels[$i],
                    'link' => $links[$i]
                ];
            }
        }

        // Update navigation in settings
        Setting::update('navigation', json_encode($navigation));

        // Send response
        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles GET api/users
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getUsers($request, $response, $args) {
        $params = $request->getParams();

        // To view users, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        // Get users
        $users = User::getMany([
            'items_per_page' => 50,
            'page' => (int) $params['page'],
            'query' => empty($params['query']) ? null : $params['query']
        ], $pagination);

        // Render post list
        $html = Admin::render('partials/user-list', [
            'users' => $users
        ]);

        // Send response
        return $response->withJson([
            'success' => true,
            'html' => $html,
            'pagination' => $pagination
        ]);
    }

    /**
    * Private method to handle add and update
    *
    * @param String $action
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    private function addUpdateUser($action, $request, $response, $args) {
        $params = $request->getParams();
        $slug = $action === 'add' ? $params['username'] : $args['slug'];

        // To add/update a user, you must be an owner or admin. Users are also allowed to update
        // their own profiles.
        if(!Session::isRole(['owner', 'admin']) && $slug !== Session::user('slug')) {
            return $response->withStatus(403);
        }

        // Make sure passwords match
        if($params['password'] !== $params['verify-password']) {
            return $response->withJson([
                'success' => false,
                'invalid' => ['password', 'verify-password'],
                'message' => Language::term('the_passwords_you_entered_do_not_match')
            ]);
        }

        // Create user array
        $user = [
            'slug' => $slug,
            'name' => $params['name'],
            'email' => $params['email'],
            'role' => $params['role'],
            'password' => empty($params['password']) ? null : $params['password'],
            'twitter' => $params['twitter'],
            'website' => $params['website'],
            'location' => $params['location'],
            'bio' => $params['bio'],
            'avatar' => $params['avatar'],
            'cover' => $params['cover']
        ];

        // Add/update the user
        try {
            if($action === 'add') {
                User::add($slug, $user);
            } else {
                User::update($slug, $user);
            }
        } catch(\Exception $e) {
            // Handle errors
            switch($e->getCode()) {
                case User::CANNOT_CHANGE_OWNER:
                    $invalid = ['role'];
                    $message = Language::term('the_owner_role_cannot_be_revoked_or_reassigned');
                    break;
                case User::INVALID_SLUG:
                    $invalid = ['username'];
                    $message = Language::term('the_username_you_entered_cannot_be_used');
                    break;
                case User::ALREADY_EXISTS:
                    $invalid = ['username'];
                    $message = Language::term('the_username_you_entered_is_already_taken');
                    break;
                case User::INVALID_NAME:
                    $invalid = ['name'];
                    $message = Language::term('the_name_you_entered_cannot_be_used');
                    break;
                case User::INVALID_EMAIL:
                    $invalid = ['email'];
                    $message = Language::term('the_email_address_you_entered_is_not_valid');
                    break;
                case User::PASSWORD_TOO_SHORT:
                    $invalid = ['password', 'verify-password'];
                    $message = Language::term('the_password_you_entered_is_too_short');
                    break;
                case User::INVALID_PASSWORD:
                    $invalid = ['password', 'verify-password'];
                    $message = Language::term('the_password_you_entered_is_not_valid');
                    break;
                default:
                    $message = $e->getMessage();
            }

            return $response->withJson([
                'success' => false,
                'invalid' => $invalid,
                'message' => $message
            ]);
        }

        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles POST api/users
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function addUser($request, $response, $args) {
        return $this->addUpdateUser('add', $request, $response, $args);
    }

    /**
    * Handles PUT api/users/{slug}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function updateUser($request, $response, $args) {
        return $this->addUpdateUser('update', $request, $response, $args);
    }

    /**
    * Handles DELETE api/users/{slug}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function deleteUser($request, $response, $args) {
        // To delete a user, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        // Delete the user
        try {
            User::delete($args['slug']);

            // Did you delete yourself? If so, cya!
            if($args['slug'] === Session::user()['slug']) Session::logout();
        } catch(\Exception $e) {
            if($e->getCode() === User::CANNOT_DELETE_OWNER) {
                return $response->withJson([
                    'success' => false,
                    'message' => Language::term('the_owner_account_cannot_be_deleted')
                ]);
            }
        }

        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles POST api/settings/update
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function updateSettings($request, $response, $args) {
        $params = $request->getParams();

        // To edit settings, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        // Create settings array
        $settings = [
            'title' => $params['title'],
            'tagline' => $params['tagline'],
            'homepage' => $params['homepage'],
            'twitter' => $params['twitter'],
            'theme' => $params['theme'],
            'posts_per_page' => $params['posts-per-page'],
            'cover' => $params['cover'],
            'logo' => $params['logo'],
            'favicon' => $params['favicon'],
            'generator' => $params['generator'] === 'on' ? 'on' : 'off',
            'default_title' => $params['default-title'],
            'default_content' => $params['default-content'],
            'language' => $params['language'],
            'timezone' => $params['timezone'],
            'head_code' => $params['head-code'],
            'foot_code' => $params['foot-code'],
            'maintenance' => $params['maintenance'] === 'on' ? 'on' : 'off',
            'maintenance_message' => $params['maintenance-message'],
            'hbs_cache' => $params['hbs-cache'] === 'on' ? 'on' : 'off'
        ];

        // Update settings
        foreach($settings as $name => $value) {
            Setting::update($name, $value);
        }

        // Send response
        return $response->withJson([
            'success' => true
        ]);
    }

    /**
    * Handles DELETE api/settings/cache
    *
    * @param $request
    * @param $response
    * @param $args
    * @return \Slim\Http\Response
    *
    **/
    public function deleteCache($request, $response, $args) {
        Cache::flush();

        // Send response
        return $response->withJson([
            'success' => true,
            'message' => Language::term('cache_has_been_cleared')
        ]);
    }

    /**
    * Returns the HTML for the backups table
    *
    * @return mixed
    **/
    private function getBackupTableHTML() {
        // To manage backups, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        return Renderer::render([
            'template' => Leafpub::path('source/templates/partials/backups-table.hbs'),
            'data' => [
                'backups' => Backup::getAll()
            ],
            'helpers' => ['admin', 'url', 'utility']
        ]);
    }

    /**
    * Handles POST api/backup
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function addBackup($request, $response, $args) {
        // To manage backups, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        try {
            $backup = Backup::create();
        } catch(\Exception $e) {
            switch($e->getCode()) {
                case Backup::UNABLE_TO_CREATE_DIRECTORY:
                    $message = Language::term('unable_to_create_directory_{dir}', [
                        'dir' => Leafpub::path('backups')
                    ]);
                    break;
                case Backup::UNABLE_TO_BACKUP_DATABASE:
                    $message = Language::term('unable_to_backup_database');
                    break;
                case Backup::UNABLE_TO_CREATE_ARCHIVE:
                    $message = Language::term('unable_to_create_backup_file');
                    break;
                default:
                    $message = $e->getMessage();
            }

            return $response->withJson([
                'success' => false,
                'message' => $message
            ]);
        }

        // Send response
        return $response->withJson([
            'success' => true,
            'html' => self::getBackupTableHTML()
        ]);
    }

    /**
    * Handles DELETE api/backup/{file}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function deleteBackup($request, $response, $args) {
        // To manage backups, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        Backup::delete($args['file']);

        // Send response
        return $response->withJson([
            'success' => true,
            'html' => self::getBackupTableHTML()
        ]);
    }

    /**
    * Handles GET api/backup/{file}
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getBackup($request, $response, $args) {
        // To manage backups, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        // Get backup
        $backup = Backup::get($args['file']);
        if(!$backup) {
            return $this->notFound($request, $response);
        }

        // Stream the file
        $stream = new \Slim\Http\Stream(fopen($backup['pathname'], 'rb'));
        return $response
            ->withHeader('Content-Type', 'application/force-download')
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Type', 'application/download')
            ->withHeader('Content-Description', 'File Transfer')
            ->withHeader('Content-Transfer-Encoding', 'binary')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $backup['filename'] . '"')
            ->withHeader('Expires', '0')
            ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
            ->withHeader('Pragma', 'public')
            ->withBody($stream);
    }

    /**
    * Handles POST api/backup/{file}/restore
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function restoreBackup($request, $response, $args) {
        $params = $request->getParams();

        // To manage backups, you must be an owner or admin
        if(!Session::isRole(['owner', 'admin'])) {
            return $response->withStatus(403);
        }

        // Verify password
        if(!User::verifyPassword(Session::user('slug'), $params['password'])) {
            return $response->withJson([
                'success' => false,
                'message' => Language::term('your_password_is_incorrect')
            ]);
        }

        // Restore the backup
        try {
            Backup::restore($args['file']);
        } catch(\Exception $e) {
            return $response->withJson([
                'success' => false,
                'message' => Language::term('unable_to_restore_backup') . ' – ' . $e->getMessage()
            ]);
        }

        // Send response
        return $response->withJson([
            'success' => true,
            'message' => Language::term('the_backup_has_been_restored')
        ]);
    }

    /**
    * Handles GET api/locater
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getLocater($request, $response, $args) {
        $params = $request->getParams();
        $html = '';
        $items = [];

        // Search menu items
        foreach(Admin::getMenuItems() as $nav) {
            if(mb_stristr($nav['title'], $params['query'])) {
                $items[] = [
                    'title' => $nav['title'],
                    'description' => null,
                    'link' => $nav['link'],
                    'avatar' => $nav['avatar'],
                    'icon' => $nav['icon']
                ];
            }
        }

        // Search posts
        $posts = Post::getMany([
            // If you're not an owner, admin, or editor then you can only see your own posts
            'author' => Session::isRole(['owner', 'admin', 'editor']) ? null : Session::user('slug'),
            'end_date' => null,
            'status' => null,
            'ignore_featured' => false,
            'ignore_pages' => false,
            'ignore_sticky' => false,
            'ignore_posts' => false,
            'items_per_page' => 5,
            'page' => 1,
            'query' => $params['query'],
            'start_date' => null,
            'tag' => null
        ]);
        foreach($posts as $post) {
            $items[] = [
                'title' => $post['title'],
                'description' => $post['slug'],
                'link' => Admin::url('posts/' . rawurlencode($post['slug'])),
                'icon' => 'fa fa-file-text'
            ];
        }

        // Search tags
        if(Session::isRole(['owner', 'admin', 'editor'])) {
            $tags = Tag::getMany([
                'query' => $params['query'],
                'page' => 1,
                'items_per_page' => 10
            ]);
            foreach($tags as $tag) {
                $items[] = [
                    'title' => $tag['name'],
                    'description' => $tag['slug'],
                    'link' => Admin::url('tags/' . rawurlencode($tag['slug'])),
                    'icon' => 'fa fa-tag'
                ];
            }
        }

        // Search users
        if(Session::isRole(['owner', 'admin'])) {
            $users = User::getMany([
                'query' => $params['query'],
                'page' => 1,
                'items_per_page' => 10
            ]);
            foreach($users as $user) {
                $items[] = [
                    'title' => $user['name'],
                    'description' => $user['slug'],
                    'link' => Admin::url('users/' . rawurlencode($user['slug'])),
                    'icon' => 'fa fa-user',
                    'avatar' => $user['avatar']
                ];
            }
        }

        // Render it
        try {
            $html = Renderer::render([
                'template' => Leafpub::path('source/templates/partials/locater-items.hbs'),
                'helpers' => ['admin', 'url', 'utility'],
                'data' => [
                    'items' => $items
                ]
            ]);
        } catch(\Exception $e) {
            $html = '';
        }

        // Send response
        return $response->withJson([
            'success' => true,
            'html' => $html
        ]);
    }

    /**
    * Handles POST api/upload
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function addUpload($request, $response, $args) {
        $params = $request->getParams();
        $uploaded = [];
        $failed = [];

        // Loop through uploaded files
        foreach($request->getUploadedFiles()['files'] as $upload) {
            $extension = Leafpub::fileExtension($upload->getClientFilename());

            // Check for a successful upload
            if($upload->getError() !== UPLOAD_ERR_OK) {
                $failed[] = [
                    'filename' => $upload->getClientFilename(),
                    'message' => Language::term('upload_failed')
                ];
                continue;
            }

            // Accept only images?
            if($params['accept'] === 'image') {
                try {
                    // Make sure it's really an image
                    switch($extension) {
                        case 'svg':
                            // Do nothing
                            break;
                        default:
                            if(!getimagesize($upload->file)) {
                                throw new \Exception('Invalid image format');
                            }
                            break;
                    }

                    // Make it a thumbnail?
                    if(isset($params['image'])) {
                        // Decode JSON string
                        $params['image'] = json_decode($params['image']);

                        // Make the image a thumbnail
                        if(isset($params['image']['thumbnail'])) {
                            $image = new \abeautifulsite\SimpleImage($upload->file);
                            $image->thumbnail(
                                $params['image']['thumbnail']->width,
                                $params['image']['thumbnail']->height
                            )->save();
                        }
                    }
                } catch(\Exception $e) {
                    $failed[] = [
                        'filename' => $upload->getClientFilename(),
                        'message' => Language::term('invalid_image_format')
                    ];
                    continue;
                }
            }

            try {
                // Add the file to uploads
                $id = Upload::add(
                    $upload->getClientFilename(),
                    file_get_contents($upload->file),
                    $info
                );
                $uploaded[] = $info;
            } catch(\Exception $e) {
                $failed[] = [
                    'filename' => $upload->getClientFilename(),
                    'message' => Language::term('unable_to_upload_file')
                ];
            }
        }

        return $response->withJson([
            'success' => true,
            'uploaded' => $uploaded,
            'failed' => $failed
        ]);
    }

    /**
    * Handles GET api/oembed
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return \Slim\Http\Response
    *
    **/
    public function getOembed($request, $response, $args) {
        $params = $request->getParams();

        // Fetch the embed code from the provider
        $embera = new \Embera\Embera();
        $code = $embera->autoEmbed($params['url']);
        if($embera->hasErrors() || $code === $params['url']) {
            return $response->withJson([
                'success' => false
            ]);
        }

        return $response->withJson([
            'success' => true,
            'code' => $code
        ]);
    }

    public function handleImportUpload($request, $response, $args){
        $params = $request->getParams();
        $uploaded = [];
        $failed = [];

        foreach($request->getUploadedFiles()['files'] as $upload) {
            $extension = Leafpub::fileExtension($upload->getClientFilename());

            // Check for a successful upload
            if($upload->getError() !== UPLOAD_ERR_OK) {
                $failed[] = [
                    'filename' => $upload->getClientFilename(),
                    'message' => Language::term('upload_failed')
                ];
                continue;
            }
            $upload->moveTo(Leafpub::path('content/uploads/import.xml'));
            $uploaded[] = Leafpub::path('content/uploads/import.xml');
        }

        return $response->withJson([
            'success' => true,
            'uploaded' => $uploaded,
            'failed' => $failed
        ]);
    }

    /**
    * Imports a blog. At the moment the dropins Wordpress and Ghost are available.
    * 
    * Steps:
    * 1. Set site in maintenance mode.
    * 2. Flush __posts & __post_tags
    * 3. ini_set(time_limit)
    * 4. Begin Transaction!
    * 5. Import 
    * 6. Load the pictures
    * 7. Commit Transaction.
    * 8. Delete import.xml ($file)
    * 9. Set maintenance mode off
    * 10. Done
    *
    * @param \Slim\Http\Request $request
    * @param \Slim\Http\Response $response
    * @param array $args
    * @return bool
    * @throws \Exception
    *
    **/
    public function doImport($request, $response, $args){
        $params = $request->getParams();
        $ret = Importer::doImport($params);
        
        return $response->withJson([
            'success' => (count($ret['succeed']) > 0),
            'uploaded' => $ret['succeed'],
            'failed' => $ret['failed']
        ]);
    }

}