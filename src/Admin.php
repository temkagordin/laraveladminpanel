<?php

namespace LaravelAdminPanel;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use LaravelAdminPanel\Events\AlertsCollection;
use LaravelAdminPanel\FormFields\After\HandlerInterface as AfterHandlerInterface;
use LaravelAdminPanel\FormFields\HandlerInterface;
use LaravelAdminPanel\Models\Category;
use LaravelAdminPanel\Models\DataRow;
use LaravelAdminPanel\Models\DataType;
use LaravelAdminPanel\Models\Menu;
use LaravelAdminPanel\Models\MenuItem;
use LaravelAdminPanel\Models\Page;
use LaravelAdminPanel\Models\Permission;
use LaravelAdminPanel\Models\Post;
use LaravelAdminPanel\Models\Role;
use LaravelAdminPanel\Models\Setting;
use LaravelAdminPanel\Models\User;
use LaravelAdminPanel\Traits\Translatable;

class Admin
{
    protected $version;
    protected $filesystem;

    protected $alerts = [];
    protected $alertsCollected = false;

    protected $formFields = [];
    protected $afterFormFields = [];

    protected $permissionsLoaded = false;
    protected $permissions = [];

    protected $users = [];

    protected $viewLoadingEvents = [];

    protected $models = [
        'Category'   => Category::class,
        'DataRow'    => DataRow::class,
        'DataType'   => DataType::class,
        'Menu'       => Menu::class,
        'MenuItem'   => MenuItem::class,
        'Page'       => Page::class,
        'Permission' => Permission::class,
        'Post'       => Post::class,
        'Role'       => Role::class,
        'Setting'    => Setting::class,
        'User'       => User::class,
    ];

    public $setting_cache = null;

    public function __construct()
    {
        $this->filesystem = app(Filesystem::class);

        $this->findVersion();
    }

    public function model($name)
    {
        return app($this->models[studly_case($name)]);
    }

    public function modelClass($name)
    {
        return $this->models[$name];
    }

    public function useModel($name, $object)
    {
        if (is_string($object)) {
            $object = app($object);
        }

        $class = get_class($object);

        if (isset($this->models[studly_case($name)]) && !$object instanceof $this->models[studly_case($name)]) {
            throw new \Exception("[{$class}] must be instance of [{$this->models[studly_case($name)]}].");
        }

        $this->models[studly_case($name)] = $class;

        return $this;
    }

    public function view($name, array $parameters = [])
    {
        foreach (array_get($this->viewLoadingEvents, $name, []) as $event) {
            $event($name, $parameters);
        }

        return view($name, $parameters);
    }

    public function onLoadingView($name, \Closure $closure)
    {
        if (!isset($this->viewLoadingEvents[$name])) {
            $this->viewLoadingEvents[$name] = [];
        }

        $this->viewLoadingEvents[$name][] = $closure;
    }

    public function formField($row, $dataType, $dataTypeContent)
    {
        $formField = $this->formFields[$row->type];

        return $formField->handle($row, $dataType, $dataTypeContent);
    }

    public function afterFormFields($row, $dataType, $dataTypeContent)
    {
        $options = json_decode($row->details);

        return collect($this->afterFormFields)->filter(function ($after) use ($row, $dataType, $dataTypeContent, $options) {
            return $after->visible($row, $dataType, $dataTypeContent, $options);
        });
    }

    public function addFormField($handler)
    {
        if (!$handler instanceof HandlerInterface) {
            $handler = app($handler);
        }

        $this->formFields[$handler->getCodename()] = $handler;

        return $this;
    }

    public function addAfterFormField($handler)
    {
        if (!$handler instanceof AfterHandlerInterface) {
            $handler = app($handler);
        }

        $this->afterFormFields[$handler->getCodename()] = $handler;

        return $this;
    }

    public function formFields()
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver", 'mysql');

        return collect($this->formFields)->filter(function ($after) use ($driver) {
            return $after->supports($driver);
        });
    }

    public function setting($key, $default = null)
    {
        if ($this->setting_cache === null) {
            foreach (Setting::all() as $setting) {
                $keys = explode('.', $setting->key);
                @$this->setting_cache[$keys[0]][$keys[1]] = $setting->value;
            }
        }

        $parts = explode('.', $key);

        if (count($parts) == 2) {
            return @$this->setting_cache[$parts[0]][$parts[1]] ?: $default;
        } else {
            return @$this->setting_cache[$parts[0]] ?: $default;
        }
    }

    public function image($file, $default = '')
    {
        if (!empty($file)) {
            return Storage::disk(config('admin.storage.disk'))->url($file);
        }

        return $default;
    }

    public function routes()
    {
        require __DIR__.'/../routes/admin.php';
    }

    public function can($permission)
    {
        $this->loadPermissions();

        // Check if permission exist
        $exist = $this->permissions->where('key', $permission)->first();

        // Permission not found
        if (!$exist) {
            throw new \Exception('Permission does not exist', 400);
        }

        $user = $this->getUser();
        if ($user == null || !$user->hasPermission($permission)) {
            return false;
        }

        return true;
    }

    public function canOrFail($permission)
    {
        if (!$this->can($permission)) {
            throw new UnauthorizedHttpException(null);
        }

        return true;
    }

    public function canOrAbort($permission, $statusCode = 403)
    {
        if (!$this->can($permission)) {
            return abort($statusCode);
        }

        return true;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function addAlert(Alert $alert)
    {
        $this->alerts[] = $alert;
    }

    public function alerts()
    {
        if (!$this->alertsCollected) {
            event(new AlertsCollection($this->alerts));

            $this->alertsCollected = true;
        }

        return $this->alerts;
    }

    protected function findVersion()
    {
        if (!is_null($this->version)) {
            return;
        }

        if ($this->filesystem->exists(base_path('composer.lock'))) {
            // Get the composer.lock file
            $file = json_decode(
                $this->filesystem->get(base_path('composer.lock'))
            );

            // Loop through all the packages and get the version of admin
            foreach ($file->packages as $package) {
                if ($package->name == 'laraveladminpanel/admin') {
                    $this->version = $package->version;
                    break;
                }
            }
        }
    }

    /**
     * @param string|Model|Collection $model
     *
     * @return bool
     */
    public function translatable($model)
    {
        if (!config('admin.multilingual.enabled')) {
            return false;
        }

        if (is_string($model)) {
            $model = app($model);
        }

        if ($model instanceof Collection) {
            $model = $model->first();
        }

        if (!is_subclass_of($model, Model::class)) {
            return false;
        }

        $traits = class_uses_recursive(get_class($model));

        return in_array(Translatable::class, $traits);
    }

    protected function loadPermissions()
    {
        if (!$this->permissionsLoaded) {
            $this->permissionsLoaded = true;

            $this->permissions = Permission::all();
        }
    }

    protected function getUser($id = null)
    {
        if (is_null($id)) {
            $id = auth()->check() ? auth()->user()->id : null;
        }

        if (is_null($id)) {
            return;
        }

        if (!isset($this->users[$id])) {
            $this->users[$id] = User::find($id);
        }

        return $this->users[$id];
    }
}
