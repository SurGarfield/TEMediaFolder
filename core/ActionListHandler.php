<?php

namespace TypechoPlugin\TEMediaFolder\Core;

use TypechoPlugin\TEMediaFolder\Services\LskyService;

class ActionListHandler
{
    const PAGED_STORAGES = ['cos', 'oss', 'bitiful', 'upyun'];

    private $action;

    public function __construct($action)
    {
        $this->action = $action;
    }

    public function cloudStorageList($storageType)
    {
        try {
            $serviceClass = $this->action->getServiceClass($storageType);
            if (!$serviceClass) {
                $this->action->sendJsonResponse(['folders' => [], 'files' => []]);
                return;
            }

            $service = new $serviceClass($this->action->getActionConfig());
            $request = $this->action->getActionRequest();
            $path = $request->get('temf_path', '');
            $result = $this->resolveStorageListResult($service, $storageType, $request, $path);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['folders' => [], 'files' => []]);
        }
    }

    public function localList()
    {
        try {
            $service = $this->action->getServiceOrFail('local');
            if ($service === null) {
                return;
            }

            $request = $this->action->getActionRequest();
            if ($request->get('temf_rebuild_index', '0') === '1' && method_exists($service, 'rebuildIndex')) {
                $service->rebuildIndex();
            }

            $files = [];
            $groups = $service->getFileGroups();

            foreach ($groups as $ym => $items) {
                foreach ($items as $item) {
                    if (!isset($item['group'])) {
                        $item['group'] = $ym;
                    }
                    $files[] = $item;
                }
            }

            $config = $this->action->getActionConfig();
            $paginationRows = max(1, intval($config->get('paginationRows', 4)));
            $this->action->sendJsonResponse([
                'ok' => true,
                'files' => $files,
                'groups' => $groups,
                'paginationRows' => $paginationRows
            ]);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => 'Failed to load local files: ' . $e->getMessage()]);
        }
    }

    public function lskyList()
    {
        try {
            $lskyService = new LskyService($this->action->getActionConfig());
            $request = $this->action->getActionRequest();
            $path = $request->get('temf_path', '');
            $useAlbum = $request->get('use_album', '0') === '1';

            if ($useAlbum) {
                $result = $lskyService->getFileList('', true);
            } else {
                $result = $lskyService->getFileList($path, false);
            }

            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['folders' => [], 'files' => []]);
        }
    }

    public function storageTypes()
    {
        try {
            $types = $this->action->getActionConfig()->getAvailableStorageTypes();
            $this->action->sendJsonResponse(['ok' => true, 'types' => $types]);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => 'Failed to get storage types: ' . $e->getMessage()]);
        }
    }

    public function multiList()
    {
        try {
            $request = $this->action->getActionRequest();
            $storageType = $request->get('storage_type', 'local');
            $path = $request->get('temf_path', '');

            if (!$this->action->ensureStorageConfigured($storageType)) {
                return;
            }

            $service = $this->action->getServiceOrFail($storageType);
            if ($service === null) {
                return;
            }

            $result = $this->resolveStorageListResult($service, $storageType, $request, $path);

            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse([
                'ok' => false,
                'msg' => 'Multi list error: ' . $e->getMessage()
            ]);
        }
    }

    private function resolveStorageListResult($service, $storageType, $request, $path)
    {
        if ($storageType === 'lsky') {
            $useAlbum = $request->get('use_album', '0') === '1';
            return $service->getFileList($path, $useAlbum);
        }

        if (in_array($storageType, self::PAGED_STORAGES, true)) {
            return $service->getFileList($path, [
                'page_token' => $request->get('temf_page_token', ''),
                'page_size' => $request->get('temf_page_size', 0),
                'folders_only' => $request->get('temf_folders_only', '0') === '1'
            ]);
        }

        return $service->getFileList($path);
    }
}
