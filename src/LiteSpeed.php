<?php

namespace GlueAgency\LiteSpeed;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\events\InvalidateElementCachesEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use GlueAgency\LiteSpeed\enums\Permission;
use GlueAgency\LiteSpeed\models\Settings;
use GlueAgency\LiteSpeed\services\CacheService;
use GlueAgency\LiteSpeed\services\CheckService;
use GlueAgency\LiteSpeed\services\EsiService;
use GlueAgency\LiteSpeed\services\PurgeService;
use GlueAgency\LiteSpeed\services\TrackerService;
use GlueAgency\LiteSpeed\utilities\CacheUtility;
use GlueAgency\LiteSpeed\web\twig\LiteSpeedVariable;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Application;
use yii\base\Event;
use yii\queue\Queue;
use yii\web\User as WebUser;
use yii\web\UserEvent;

/**
 * LiteSpeed Cache integration: cache control, tags, vary and purging.
 *
 * @property-read CacheService $cache
 * @property-read CheckService $check
 * @property-read EsiService $esi
 * @property-read PurgeService $purge
 * @property-read TrackerService $tracker
 *
 * @method static LiteSpeed getInstance()
 * @method Settings getSettings()
 *
 * @author    Glue Agency <support@glue.be>
 * @copyright Glue Agency
 * @license   MIT
 */
class LiteSpeed extends Plugin
{
    public string $schemaVersion = '1.1.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public bool $hasReadOnlyCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'cache'   => CacheService::class,
                'check'   => CheckService::class,
                'esi'     => EsiService::class,
                'purge'   => PurgeService::class,
                'tracker' => TrackerService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@litespeed', __DIR__);

        $this->registerLogTarget();
        $this->registerTwigVariable();

        Craft::$app->onInit(function() {
            $this->registerControllers();
            $this->registerCpTemplateRoots();
            $this->registerCpRoutes();
            $this->registerPermissions();
            $this->registerUtility();
            $this->registerTrackerCleanup();

            if ($this->getSettings()->isEnabled()) {
                $this->registerCacheOptions();
                $this->registerPurgeTriggers();
                $this->registerResponseHandlers();

                if ($this->getSettings()->isEsiEnabled()) {
                    $this->registerPrivateIdentity();
                }
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Settings → Plugins lands on the same screen as the section's own Settings item.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('litespeed/settings'));
    }

    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->getSettingsResponse();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $item['subnav'] = [
            'purge' => [
                'label' => Craft::t('litespeed', 'Purge'),
                'url'   => 'litespeed',
            ],
        ];

        $user = Craft::$app->getUser()->getIdentity();

        if ($user?->admin) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('litespeed', 'Settings'),
                'url'   => 'litespeed/settings',
            ];
        }

        return $item;
    }

    /**
     * Purges and relays get a log of their own, `storage/logs/litespeed-*.log`, instead of drowning in web.log.
     */
    protected function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name'            => 'litespeed',
            'categories'      => ['litespeed'],
            'level'           => LogLevel::INFO,
            'logContext'      => false,
            'allowLineBreaks' => false,
            'formatter'       => new LineFormatter(
                format: "%datetime% [%level_name%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }

    protected function registerControllers(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'GlueAgency\\LiteSpeed\\console\\controllers';

            return;
        }

        $this->controllerNamespace = 'GlueAgency\\LiteSpeed\\controllers';
    }

    protected function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['litespeed/settings'] = 'litespeed/settings/edit';
        });
    }

    protected function registerCpTemplateRoots(): void
    {
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
            $event->roots['litespeed'] = __DIR__ . '/templates';
        });
    }

    /**
     * Bound from init() rather than onInit(): Craft can build the `craft` Twig global before onInit runs.
     */
    protected function registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('litespeed', LiteSpeedVariable::class);
        });
    }

    protected function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading'     => Craft::t('litespeed', 'LiteSpeed'),
                'permissions' => [
                    Permission::PURGE_TAGS->value => ['label' => Permission::PURGE_TAGS->label()],
                ],
            ];
        });
    }

    protected function registerUtility(): void
    {
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = CacheUtility::class;
        });
    }

    /**
     * The record outlives a disabled plugin, so its expired pages are cleaned up either way.
     */
    protected function registerTrackerCleanup(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, fn() => $this->tracker->deleteExpired());
    }

    protected function registerCacheOptions(): void
    {
        Event::on(ClearCaches::class, ClearCaches::EVENT_REGISTER_CACHE_OPTIONS, function(RegisterCacheOptionsEvent $event) {
            $event->options[] = [
                'key'    => 'litespeed',
                'label'  => Craft::t('litespeed', 'LiteSpeed cache'),
                'info'   => Craft::t('litespeed', 'Every page LiteSpeed cached for this site'),
                'action' => fn() => $this->purge->purgeAll(),
            ];
        });
    }

    protected function registerPurgeTriggers(): void
    {
        Event::on(Elements::class, Elements::EVENT_INVALIDATE_CACHES, function(InvalidateElementCachesEvent $event) {
            $this->purge->handleInvalidation($event);
        });

        Event::on(Gc::class, Gc::EVENT_RUN, fn() => $this->purge->handleGcRun());

        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn() => $this->purge->relay());
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, fn() => $this->purge->relay());

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->on(Application::EVENT_AFTER_REQUEST, fn() => $this->purge->relay());
        }
    }

    protected function registerPrivateIdentity(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Event::on(WebUser::class, WebUser::EVENT_AFTER_LOGIN, fn(UserEvent $event) => $this->esi->handleLogin($event));
        Event::on(WebUser::class, WebUser::EVENT_AFTER_LOGOUT, fn() => $this->esi->handleLogout());

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            if (! $event->element instanceof User || $event->element->id !== Craft::$app->getUser()->getId()) {
                return;
            }

            $this->purge->purgePrivate();
        });
    }

    protected function registerResponseHandlers(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Craft::$app->on(Application::EVENT_BEFORE_ACTION, fn() => $this->cache->startCollecting());

        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            if ($event->sender !== Craft::$app->getResponse()) {
                return;
            }

            $this->purge->prepareResponse($event->sender);
            $this->cache->prepareResponse($event->sender);
        });

        Event::on(Response::class, Response::EVENT_AFTER_SEND, function() {
            $this->tracker->record();
            $this->purge->relay();
        });
    }
}
