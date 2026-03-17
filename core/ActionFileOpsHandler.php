<?php

namespace TypechoPlugin\TEMediaFolder\Core;

class ActionFileOpsHandler
{
    private $action;

    public function __construct($action)
    {
        $this->action = $action;
    }

    public function cloudRename($storageType)
    {
        try {
            $service = $this->action->getServiceOrFail($storageType);
            if ($service === null) {
                return;
            }

            if (!method_exists($service, 'renameFile')) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持重命名']);
                return;
            }

            $request = $this->action->getActionRequest();
            $fileUrl = $request->get('file_url', '');
            $newName = $request->get('new_name', '');
            $fileId = $request->get('file_id', '');

            if ($fileUrl === '' || $newName === '') {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $result = $service->renameFile($fileUrl, $newName, $fileId);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()]);
        }
    }

    public function cloudDelete($storageType)
    {
        try {
            $service = $this->action->getServiceOrFail($storageType);
            if ($service === null) {
                return;
            }

            if (!method_exists($service, 'deleteFile')) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持删除']);
                return;
            }

            $fileUrls = $this->action->collectFileUrls();
            if (empty($fileUrls)) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $request = $this->action->getActionRequest();
            $fileIds = $request->getArray('file_ids');
            $results = [];
            foreach ($fileUrls as $index => $url) {
                $fileId = $this->action->resolveFileId($fileIds, $index, $url);
                $results[] = $service->deleteFile($url, $fileId);
            }

            foreach ($results as $res) {
                if (!$res['ok']) {
                    $this->action->sendJsonResponse(['ok' => false, 'results' => $results]);
                    return;
                }
            }

            $this->action->sendJsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    public function multiDelete()
    {
        try {
            $request = $this->action->getActionRequest();
            $storageType = $request->get('storage_type', '');
            $fileUrls = $this->action->collectFileUrls();

            if ($storageType === '' || empty($fileUrls)) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->action->getServiceOrFail($storageType);
            if ($service === null) {
                return;
            }

            if (!method_exists($service, 'deleteFile')) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持删除']);
                return;
            }

            $fileIds = $request->getArray('file_ids');
            $results = [];
            foreach ($fileUrls as $index => $url) {
                $fileId = $this->action->resolveFileId($fileIds, $index, $url);
                $results[] = $service->deleteFile($url, $fileId);
            }

            $hasError = false;
            foreach ($results as $res) {
                if (!$res['ok']) {
                    $hasError = true;
                    break;
                }
            }

            if ($hasError) {
                $this->action->sendJsonResponse(['ok' => false, 'results' => $results]);
            } else {
                $this->action->sendJsonResponse(['ok' => true]);
            }
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    public function localRename()
    {
        try {
            $request = $this->action->getActionRequest();
            $fileUrl = $request->get('file_url', '');
            $newName = $request->get('new_name', '');

            if ($fileUrl === '' || $newName === '') {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->action->getServiceOrFail('local');
            if ($service === null) {
                return;
            }

            $result = $service->renameFile($fileUrl, $newName);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()]);
        }
    }

    public function localDelete()
    {
        try {
            $fileUrls = $this->action->collectFileUrls();

            if (empty($fileUrls)) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->action->getServiceOrFail('local');
            if ($service === null) {
                return;
            }

            $results = [];
            foreach ($fileUrls as $url) {
                $results[] = $service->deleteFile($url);
            }

            $hasError = false;
            foreach ($results as $res) {
                if (!$res['ok']) {
                    $hasError = true;
                    break;
                }
            }

            if ($hasError) {
                $this->action->sendJsonResponse(['ok' => false, 'results' => $results]);
            } else {
                $this->action->sendJsonResponse(['ok' => true]);
            }
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    public function multiRename()
    {
        try {
            $request = $this->action->getActionRequest();
            $storageType = $request->get('storage_type', '');
            $fileUrl = $request->get('file_url', '');
            $newName = $request->get('new_name', '');
            $fileId = $request->get('file_id', '');

            if ($storageType === '' || $fileUrl === '' || $newName === '') {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '参数不完整']);
                return;
            }

            $service = $this->action->getServiceOrFail($storageType);
            if ($service === null) {
                return;
            }

            if (!method_exists($service, 'renameFile')) {
                $this->action->sendJsonResponse(['ok' => false, 'msg' => '当前存储暂不支持重命名']);
                return;
            }

            $result = $service->renameFile($fileUrl, $newName, $fileId);
            $this->action->sendJsonResponse($result);
        } catch (\Throwable $e) {
            $this->action->sendJsonResponse(['ok' => false, 'msg' => '重命名失败: ' . $e->getMessage()]);
        }
    }
}
