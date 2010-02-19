<?php
class bugfreeConvertModel extends convertModel
{
    public $map      = array();
    public $filePath = '';
    public function __construct()
    {
        parent::__construct();
        parent::connectDB();
    }

    /* 检查Tables。*/
    public function checkTables()
    {
        return true;
    }

    /* 检查安装路径。*/
    public function checkPath()
    {
        $this->setPath();
        return file_exists($this->filePath);
    }

    /* 设置附件路径。*/
    public function setPath()
    {
        $this->filePath = realpath($this->post->installPath) . $this->app->getPathFix() . 'BugFile' . $this->app->getPathFix();
    }

    /* 执行转换。*/
    public function execute()
    {
        $this->clear();
        $this->convertUser();
        $this->convertProject();
        $this->convertModule();
        $this->fixModulePath();
        $this->convertBug();
        $this->convertAction();
        $this->convertFile();
    }

    public function convertUser()
    {
        /* 查询当前系统中存在的用户。*/
        $activeUsers = $this->dao
            ->dbh($this->sourceDBH)
            ->select("{$this->app->company->id} AS company, username AS account, userpassword AS password, realname, email")
            ->from('BugUser')
            ->orderBy('userID ASC')
            ->fetchAll('account');

        /* 查找曾经出现过的用户。*/
        $allUsers = $this->dao->select("distinct(username) AS account")->from('BugHistory')->fetchPairs();

        /* 合并二者。*/
        foreach($allUsers as $key => $account)
        {
            if(isset($activeUsers[$account])) 
            {
                $allUsers[$key] = $activeUsers[$account];
            }
            else
            {
                $allUsers[$key] = array('company' => $this->app->company->id, 'account' => $account, 'realname' => $account, 'status' => 'delete');
            }
        }
        foreach($activeUsers as $account => $user) if(!isset($allUsers[$account])) $allUsers[$account] = $user;

        /* 导入到zentao数据库中。*/
        foreach($allUsers as $account => $user)
        {
            if(!$this->dao->dbh($this->dbh)->findByAccount($account)->from(TABLE_USER)->fetch('account'))
            {
                $this->dao->dbh($this->dbh)->insert(TABLE_USER)->data($user)->exec();
            }
        }
    }

    /* 转换项目为产品。*/
    public function convertProject()
    {
        $projects = $this->dao->dbh($this->sourceDBH)->select("projectID AS id, projectName AS name, {$this->app->company->id} AS company")->from('BugProject')->fetchAll('id');
        foreach($projects as $projectID => $project)
        {
            unset($project->id);
            $this->dao->dbh($this->dbh)->insert(TABLE_PRODUCT)->data($project)->exec();
            $this->map['product'][$projectID] = $this->dao->lastInsertID();
        }
    }

    /* 转换原来的模块为Bug视图模块。*/
    public function convertModule()
    {
        $this->map['module'][0] = 0;
        $modules = $this->dao
            ->dbh($this->sourceDBH)
            ->select(
                'moduleID AS id, 
                projectID AS product, 
                moduleName AS name, 
                moduleGrade AS grade, 
                parentID AS parent, 
                "bug" AS view')
            ->from('BugModule')
            ->orderBy('id ASC')
            ->fetchAll('id');
        foreach($modules as $moduleID => $module)
        {
            $module->product = $this->map['product'][$module->product];
            unset($module->id);
            $this->dao->dbh($this->dbh)->insert(TABLE_MODULE)->data($module)->exec();
            $this->map['module'][$moduleID] = $this->dao->lastInsertID();
        }

        /* 更新parent。*/
        foreach($this->map['module'] as $oldModuleID => $newModuleID)
        {
            $this->dao->dbh($this->dbh)->update(TABLE_MODULE)->set('parent')->eq($newModuleID)->where('parent')->eq($oldModuleID)->exec();
        }
    }

    /* 修复模块路径。*/
    public function fixModulePath()
    { 
        $modules = $this->dao->dbh($this->dbh)->select('*')->from(TABLE_MODULE)->where('view')->eq('bug')->fetchAll('id');
        foreach($modules as $moduleID => $module)
        {
            if($module->grade == 1)
            {
                $path = ",$moduleID,";
            }
            else
            {
                if(!isset($modules[$module->parent])) continue;
                $parentModule = $modules[$module->parent];
                $path = $parentModule->path . $moduleID . ',';
            }
            $this->dao->update(TABLE_MODULE)->set('path')->eq($path)->where('id')->eq($moduleID)->exec();
        }
    }

    /* 转换Bug。*/
    public function convertBug()
    {
        $bugs = $this->dao
            ->dbh($this->sourceDBH)
            ->select('
            bugID AS id, 
            projectID AS product, 
            moduleID AS module,
            bugTitle AS title,
            bugSeverity AS severity,
            bugType AS type,
            bugOS AS os,
            bugStatus AS status,
            mailto,
            openedBy, openedDate, openedBuild,
            assignedTo, assignedDate,
            resolvedBy, resolution, resolvedBuild, resolvedDate,
            closedBy, closedDate,
            lastEditedBy, lastEditedDate,
            linkID as duplicateBug
            ')
            ->from('BugInfo')
            ->orderBy('bugID')
            ->fetchAll('id');
        foreach($bugs as $bugID => $bug)
        {
            $bugID = (int)$bugID;
            unset($bug->id);
            if($bug->assignedTo == 'Closed') $bug->assignedTo = 'closed';
            $bug->type       = strtolower($bug->type);
            $bug->os         = strtolower($bug->os);
            $bug->browser    = 'all';
            $bug->resolution = str_replace(' ','', strtolower($bug->resolution));
            $bug->product    = $this->map['product'][$bug->product];
            $bug->module     = $this->map['module'][$bug->module];
            $this->dao->dbh($this->dbh)->insert(TABLE_BUG)->data($bug)->exec();
            $this->map['bug'][$bugID] = $this->dao->lastInsertID();
        }

        /* 更新duplicateBug。 */
        foreach($this->map['bug'] as $oldBugID => $newBugID)
        {
            $this->dao->dbh($this->dbh)->update(TABLE_BUG)->set('duplicateBug')->eq($newBugID)->where('duplicateBug')->eq($oldBugID)->exec();
        }
    }

    /* 转换历史记录。*/
    public function convertAction()
    {
        $actions = $this->dao
            ->dbh($this->sourceDBH)
            ->select(
                "{$this->app->company->id} AS company, 
                'bug' AS objectType, 
                bugID AS objectID, 
                userName AS actor, 
                action, 
                fullInfo AS comment, 
                actionDate AS date")
            ->from('BugHistory')
            ->orderBy('bugID, historyID')
            ->fetchGroup('objectID');
        foreach($actions as $bugID => $bugActions)
        {
            /* 获得转换之后的bugID。*/
            $bugID       = (int)$bugID;
            $zentaoBugID = $this->map['bug'][$bugID];

            /* 处理action。*/
            foreach($bugActions as $key => $action)
            {
                $action->date     = strtotime($action->date);
                $action->objectID = $zentaoBugID;
                if($key == 0)
                {
                    $this->dao->dbh($this->dbh)->update(TABLE_BUG)->set('steps')->eq($action->comment)->where('id')->eq($zentaoBugID)->exec();
                    $action->comment = '';
                }
                $this->dao->dbh($this->dbh)->insert(TABLE_ACTION)->data($action)->exec();
            }
        }
    }

    /* 转换附件。*/
    public function convertFile()
    {
        $this->setPath();
        $files = $this->dao->dbh($this->sourceDBH)
            ->select(
                "{$this->app->company->id} AS company, 
                fileName AS pathname,
                fileTitle AS title,
                fileType AS extension,
                fileSize AS size,
                'bug' AS objectType,
                bugID AS objectID,
                addUser AS addedBy,
                addDate AS addedDate
                ")
            ->from('BugFile')
            ->orderBy('fileID')
            ->fetchAll();
        foreach($files as $file)
        {
            $file->objectID = $this->map['bug'][(int)$file->objectID];
            if(strpos($file->size, 'KB')) $file->size = (int)(str_replace('KB', '', $file->size) * 1024); 
            if(strpos($file->size, 'MB')) $file->size = (int)(str_replace('MB', '', $file->size) * 1024 * 1024); 
            $this->dao->dbh($this->dbh)->insert(TABLE_FILE)->data($file)->exec();

            /* 拷贝文件。*/
            $soureFile  = $this->filePath . $file->pathname;
            if(!file_exists($soureFile)) continue;
            $targetFile = $this->app->getAppRoot() . "www/data/upload/{$this->app->company->id}/" . $file->pathname;
            $targetPath = dirname($targetFile);
            if(!is_dir($targetPath)) mkdir($targetPath, 0777, true);
            copy($soureFile, $targetFile);
        }
    }

    /* 清空导入之后的数据。*/
    public function clear()
    {
        foreach($this->session->state as $table => $maxID)
        {
            $this->dao->dbh($this->dbh)->delete()->from($table)->where('id')->gt($maxID)->exec();
        }
    }
}
