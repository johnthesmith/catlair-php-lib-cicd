<?php
/*
    Модуль CICD
    Включает:
        - работа с GIT
        - shell операции на локальном и удаленном хостах через ssh
        - сборка docker
        - обфурскация кода
        - проверка корректности кода
        - массовые подмены
*/



namespace catlair;



/*
    Локальные библиотеки
*/
require_once LIB . '/core/shell.php';
require_once LIB . '/core/utils.php';
require_once LIB . '/core/http.php';
require_once LIB . '/core/moment.php';



/*
    Класс деплоера
*/
class Cicd extends Payload
{
    /*
        Modes
    */

    /*
        Режим сборки образа
        выполняются только действия на локальном инстансе
    */
    const MODE_BUILD    = 'build';
    /*
        Режим тестирования
        никаких действий не выполняется, только логирование
    */
    const MODE_TEST     = 'test';
    /*
        Полная сборка и деплой образа на целевой инстанс
    */
    const MODE_FULL     = 'full';



    /* Default paremeters */
    const DEFAULT_PARAMS =
    [
        /*
            Set aliases
        */

        /* Путь до общего списка фалов */
        'FILES'                 => '%SOURCE%/files',
        /* Путь до папки с шаблонами */
        'TEMPLATES'             => '%SOURCE%/templates',

        /*
            Источники локальные
        */

        /* папка путей источников для создания контейнера */
        'SOURCE'                => '%TASK_PATH%/deploy/source',
        /* источник для формирмирования корня */
        'IMAGE'                 => '%SOURCE%/image',
        /* Путь до списка фалов задачи */
        'FILES_TASK'            => '%SOURCE%/files',
        'SOURCE_PROJECT'        => '%SOURCE%/project',

        /*
            Направления локальные
        */

        /* путь до кэша */
        'CACHE'                 => '%TASK_PATH%/deploy/cache',
        /* кэш стабильности файла */
        'CACHE_STABLE'          => '%CACHE%/stable',
        /* папка в которой собирается задача */
        'DEST'                  => '%TASK_PATH%/deploy/dest',
        /* временная папка для задачи */
        'TMP'                   => '%DEST%/tmp',
        /* папка для билда проекта. в этой папке содержится собранный проект*/
        'BUILD'                 => '%DEST%/image',
        /* папка для экспорта образов контейнеров */
        'IMAGES'                => '%DEST%/images',
        /* папка в которой будет размещен продукт в локальной сборке */
        'LOCAL_PROJECT'         => '%BUILD%/%REMOTE_PROJECT%',
        'REMOTE_PROJECT_APP'    => '%REMOTE_PROJECT%/app',
        /* файл версии */
        'VERSION_FILE'          => '%TASK_PATH%/version.json',

        /*
            Направления удаленные
        */

        /* подключение к удаленному хосту */
        'REMOTE'                => '%REMOTE_USER%@%REMOTE_HOST%',
        /* путь на удаленном хосте где будет выполнена распаковка файла контейнера */
        'REMOTE_IMAGES'         => '/tmp/deployer/images',

        /*
            Параметры
        */
        'DeployMoment'          => '',
        /* имя образа файла докера с текущей версией. Заполняется при каждом Prep */
        'IMAGE_FILE_CURRENT'    => 'UNDEFINED'
    ];

    /* List of files removed by the gitPurge command */
    private $gitPurgeList =
    [
        '.git',
        '.gitignore',
        'README.md',
        'push'
    ];

    /* Current mode */
    private $Mode       = self::MODE_TEST;
    /* Current fon (empty) */
    private $activeFob  = [];



    /**************************************************************************
        Build preparation must be invoked before execution
    */
    public function prepare
    (
        string $aRoot = null
    )
    {
        $this
        /* Adding static default parameters */
        -> addParams( self::DEFAULT_PARAMS )
        /* Adding dynamic parameters */
        -> addParams
        ([
            /* Path to the current task */
            'TASK_PATH' =>
            $ARoot == null ? $_SERVER[ 'PWD' ] : $ARoot,
            /* Key fob file with security parameters */
            'FOB_FILE' =>
            clValueFromObject( $_SERVER, 'HOME' ) . '/fob.json',
            /* Build start moment */
            'CI_MOMENT' =>
            Moment::Create() -> now() -> toStringODBC()
        ]);
        return $this;
    }



    /*************************************************************************
        GIT utils
    */


    /*
        Clones a repository into the specified destination
    */
    public function gitClone
    (
        /* Source repository URL to clone from */
        string $aSource,
        /* File path destination to clone into */
        string $aDest,
        /* Branch to clone */
        string $aBranch     = '',
        /* Comment for logging during clone */
        string $aComment    = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git clone' )
            -> cmdAdd( '--depth 1' )
            -> cmdAdd( empty( $aBranch ) ? '' : '-b ' . $aBranch )
            -> cmdAdd( $aSource )
            -> fileAdd( $this -> prep( $aDest ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Deletes git files from the directory
        The contents cease to be a git project
    */
    public function gitPurge
    (
        /* File path where deletion is performed */
        string $aSource,
        /* Optional comment for logging */
        string $aComment = ''
    )
    {
        if( $this -> isOk())
        {
            foreach( $this -> gitPurgeList as $item )
            {
                $this -> delete( $aSource . '/' . $item, 'Git purge' );
            }
        }
        return $this;
    }



    /*
        Clones a repository into the specified folder and removes git files
        Uses GitClone and GitPurge
    */
    public function gitSync
    (
        /* Source repository URL to clone from */
        string  $ASource,
        /* Destination file path for copying */
        string  $ADestination,
        /* List of exclusions */
        array   $AExclude       = [],
        /* Optional branch to clone */
        string  $ABranch        = ''
    )
    {
        /* Define temporary path */
        $tmp = '%TMP%/' . clGUID();

        return $this
        /* Clone the repository into the temporary folder */
        -> gitClone( $this -> prep( $aSource ), $tmp, $aBranch )
        /* Remove git metadata files */
        -> gitPurge( $tmp, 'Remove git structure' )
        /* Копирование в локальную папку проекта */
        -> sync
        (
            $tmp,
            $aDestination,
            $aExclude,
            true,
            'Copy sources to build',
            $this -> IsTest()
        );
    }


    /*
        Git pull
    */
    public function gitPull
    (
        /* Файловый путь направление для пулинга */
        string $aDest,
        /* Необязательный коментарий для логирования */
        string $aComment = ''
    )
    {
        if( $this -> isOk())
        {
            $dest = $this -> prep( $aDest );

            /* Store current folder */
            $currentPath = getcwd();
            /* Change current folder to $dest*/
            chdir( $dest );

            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git pull' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
            /* Restore current folder */
            chdir( $currentPath );
        }
        return $this;
    }



    public function gitAdd
    (
        /* Файловый путь источник пушинга */
        string $ASource,
        /* Необязательный коментарий для логирования */
        string $AComment = ''
    )
    {
        if( $this -> isOk())
        {
            $Source = $this -> prep( $ASource );
            $CurrentPath = getcwd();

            changeFolder( $Source );

            Shell::create( $this -> getLog() )
            -> setComment( $AComment )
            -> cmdBegin()
            -> cmdAdd( 'git add -A' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            chdir( $CurrentPath );
        }
        return $this;
    }



    public function gitCommit
    (
        /* Файловый путь источник пушинга */
        string $ASource,
        /* Необязательный коментарий для логирования */
        string $AComment = ''
    )
    {
        if( $this -> isOk())
        {
            $Source = $this -> prep( $ASource );
            $CurrentPath = getcwd();

            changeFolder( $Source );

            Shell::create( $this -> getLog() )
            -> setComment( $AComment )
            -> cmdBegin()
            -> cmdAdd( 'git commit -m ' . '"autocommit"' )
            -> cmdEnd( ' ', $this -> isTest() )
//            -> resultTo( $this )
            ;

            chdir( $CurrentPath );
        }
        return $this;
    }



    public function gitPush
    (
        /* Файловый путь источник пушинга */
        string $ASource,
        /* Необязательный коментарий для логирования */
        string $AComment = ''
    )
    {
        if( $this -> isOk())
        {
            $Source = $this -> prep( $ASource );
            $CurrentPath = getcwd();

            changeFolder( $Source );

            Shell::create( $this -> getLog() )
            -> setComment( $AComment )
            -> cmdBegin()
            -> cmdAdd( 'git push' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            chdir( $CurrentPath );
        }
        return $this;
    }



    public function gitCloneOrPull
    (
        /* Репозиторий источник для клонирования */
        string $ASource,
        /* Файловый путь направление для клонирования */
        string $ADest,
        /* Необязательная ветка для клонирования */
        string $ABranch     = '',
        /* Необязательный коментарий при клонировании для лога */
        string $AComment    = ''
    )
    {
        if( file_exists( $this -> prep( $ADest . '/.git' )))
        {
            $this -> gitPull( $ADest, $AComment);
        }
        else
        {
            $this -> gitClone( $ASource, $ADest, $ABranch, $AComment);
        }
        return $this;
    }



    /**************************************************************************
        Работа с FS
    */

    public function changeFolder
    (
        string $AFolder = '%BUILD%'
    )
    {
        if ( $this -> isOk() )
        {
            $Folder = $this -> prep( $AFolder );
            $this
            -> getLog()
            -> trace( 'Change folder' )
            -> param( 'Folder', $Folder );

            if( file_exists( $Folder ) && is_dir( $Folder ))
            {
                chdir( $Folder );
            }
            else
            {
                $this -> setResult
                (
                    'FolderNotExists',
                    [ 'Folder' => $Folder ]
                );
            }
        }
        return $this;
    }



    /*
        Изменение перечня прав на папки и файлы
    */
    public function rights
    (
        /* Список путей для раздачи прав */
        array   $APathes        = [],
        /* Права на папки */
        string  $ARights        = '0700',
        /* Рекурсивная обработка */
        bool    $ARecursion     = false,
        string  $AComment       = ''
    )
    {
        if( $this -> isOk() )
        {
            foreach( $APathes as $Path )
            {
                $Path = $this -> prep( $Path );
                if( file_exists( $Path ) && $this -> isOk() )
                {
                    Shell::create( $this -> GetLog() )
                    -> setComment( $AComment )
                    -> cmdBegin ()
                    -> cmdAdd   ( 'chmod' )
                    -> cmdAdd   ( $ARights )
                    -> cmdAdd   ( $ARecursion ? '-R' : '' )
                    -> cmdAdd   ( $Path )
                    -> cmdEnd   ( ' ', $this -> isTest() )
                    -> resultTo ( $this );
                }
                else
                {
                    if( !$this -> isTest() )
                    {
                        $this -> setResult
                        (
                            'PathNotFoundForChangeRights',
                            [ 'Path' => $Path ]
                        );
                    }
                }
            }
        }
        return $this;
    }



    /*
        Рекурсивное копирование файлов
    */
    public function sync
    (
        /* файловый путь источник копирвоания */
        string  $ASource,
        /* файловый путь направление копирвоания */
        string  $ADestination,
        /* массив строковых масок исключений  при копировании*/
        array   $AExcludes      = [],
        /* допускается удаление файлов в направлении не содержащихся в источнике */
        bool    $ADelete        = false,
        /* коментарий для вывода при исполнении команды */
        string  $AComment       = '',
        /* запуск в режиме тестирования */
        bool    $AIsTest        = true
    )
    {
        if( $this -> isOk() )
        {
            $this -> GetLog() -> Begin( 'Syncronize' ) -> Text( ' ' . $AComment );

            /* Конверсия путей в полные */
            $Source = $this -> Prep( $ASource );
            $Source = $Source . ( is_dir( $Source ) ? '/' : '' );
            $Destination = $this -> Prep( $ADestination );

            $this -> GetLog()
            -> Trace() -> Param( 'Source', $Source )
            -> Trace() -> Param( 'Destination', $Destination );

            /* Проверка существования источника */
            if( ! $this -> IsTest() )
            {
                $this -> checkPath( $ADestination );
            }

            if( $this -> isOk() )
            {
                $Shell = Shell::Create( $this -> GetLog() );

                $Shell
                -> CmdBegin()
                -> CmdAdd( 'rsync' )
                -> CmdAdd( '-rzaog' );

                /* Добавление исключений для синхронизации */
                if( !empty( $AExcludes ))
                {
                    foreach( $AExcludes as $Exclude )
                    {
                        $Shell -> CmdAdd
                        (
                            '--exclude "' .
                            $this -> Prep( $Exclude ) .
                            '" '
                        );
                    }
                }

                if( $ADelete )
                {
                    $Shell -> CmdAdd( '--delete' );
                }

                $Shell
                -> FileAdd( $Source )
                -> FileAdd( $Destination )
                -> CmdEnd( ' ', $AIsTest )
                -> ResultTo( $this );
            }
            $this -> GetLog() -> End();
        }
        return $this;
    }



    /*
        Recurcive folders removeal
    */
    public function delete
    (
        string $APath,
        string $AComment = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::Create( $this -> getLog() )
            -> setComment( $AComment )
            -> cmdBegin()
            -> cmdAdd( 'rm' )
            -> cmdAdd( '-rf' )
            -> fileAdd( $this -> prep( $APath ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }




    /*
        Перемещение папки
    */
    public function move
    (
        string $ASource,
        string $ADest,
        string $AComment = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::Create( $this -> getLog() )
            -> setComment( $AComment )
            -> cmdBegin()
            -> cmdAdd( 'mv' )
            -> fileAdd( $this -> prep( $ASource ) )
            -> fileAdd( $this -> prep( $ADest ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Копирование папки
    */
    public function copy
    (
        string $ASource,
        string $ADest,
        string $AComment = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::Create( $this -> getLog() )
            -> setComment( $AComment )
            -> cmdBegin()
            -> cmdAdd( 'cp -aT' )
            -> fileAdd( $this -> prep( $ASource ) )
            -> fileAdd( $this -> prep( $ADest ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Рекурсивное создание фаловых путей
    */
    public function checkPath
    (
        /* файловый путь для создания */
        string $APath
    )
    {
        if( $this -> isOk())
        {
            $Path = $this -> prep( $APath );
            $Cmd = $this -> parseCLI( $Path );

            if( $Cmd[ 'Remote' ])
            {
                /*
                    Исполнение кода создания папки на удаленном хосте без
                    проверки результата
                */
                Shell::Create( $this -> getLog() )
                -> setConnection( $Cmd[ 'Connection' ] )
                -> setPrivateKeyPath( $this -> getParam( 'REMOTE_SSL_KEY' ))
                -> cmd( 'mkdir -p ' . $Cmd[ 'Path' ], ! $this -> IsFull() )
                -> getResult();
            }
            else
            {
                /*
                    Проверка наличия папки на локальном хосте и создание при
                    отсутсвии
                */
                if
                (
                    !$this -> isTest() &&
                    !clCheckPath( $this -> prep( $APath ))
                )
                {
                    $this -> setResult
                    (
                        'DirectoryCheckError',
                        [ 'Path' => $APath ]
                    );
                }
            }
        }
        return $this;
    }



    /*
        Разбор CLI строки на компоненты
        Результатом разбора является массив
            bool    Remote - признак, есть ли в строке логин и хост для удаленного подключения
            string  Connection - при наличии login@host
            string  Login - логин для подключения
            string  Host - узел для подключения
            string  Path - путь (возвращается всегда)
    */
    private function parseCLI
    (
        /* Cli путь */
        string $ACLI
    )
    {
        $Result = [];
        $Lexemes = explode( ':/', $ACLI, 2 );
        if( count( $Lexemes ) == 2 )
        {
            $Result[ 'Remote' ] = true;
            $Connection = $Lexemes[ 0 ];
            $Result[ 'Connection' ] = $Connection;
            $LexemesConnection = explode( '@', $Connection );
            if( count( $LexemesConnection ) == 2 )
            {
                $Result[ 'Login' ] = $LexemesConnection[ 0 ];
                $Result[ 'Host' ] = $LexemesConnection[ 1 ];
            }
            $Result[ 'Path' ] = '/' . $Lexemes[ 1 ];
        }
        else
        {
            $Result[ 'Remote' ] = false;
            $Result[ 'Path' ] = $Lexemes[ 0 ];
        }
        return $Result;
    }



    /**************************************************************************
        Docker контейнеры
    */

    /*
        Авторризация в docker
        При авторизации используется брелок activateFob
    */
    public function dockerLogin()
    {
        if( $this -> isOk() )
        {
            Shell::create( $this -> getLog() )
            -> setComment( 'Docker login' )
            -> cmd
            (
                $this -> prep
                (
                    'docker login %Host% --username %Login% --password-stdin < %PasswordFile%'
                ),
                $this -> isTest()
            )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Сборка контейнера
    */
    public function dockerImageBuild()
    {
        if( $this -> isOk() )
        {
            $this -> getLog()
            -> begin( 'Image build' )
            -> param( 'Current version', $this -> VersionToString( 0 ))
            -> param( 'Next version', $this -> VersionToString( 1 ))
            ;

            /* Building container */
            $Shell = Shell::Create( $this -> GetLog() )
            -> setComment( 'Build the docker image' )
            -> cmdBegin()
            -> cmdAdd( 'cd "' . $this -> Prep( '%BUILD%' ) . '";' )
            -> cmdAdd
            (
                'docker build -t ' .
                $this -> GetImageBuild( +1 ) .
                ' -f ' .
                $this -> Prep( '%BUILD%/Dockerfile' ) .
                ' . '
            )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            /* Set container id if building success */
            if( $this -> isOk() && !$this -> isTest() )
            {
                $this -> ImageID = $Shell -> getResultByKey( 'Successfully built' );
                if( empty( $this -> ImageID ))
                {
                    $this -> setResult( 'IDImageNotFound', $this -> GetImageBuild( +1 ));
                }
                else
                {
                   $this -> versionInc();
                }
            }

            $this -> getLog() -> end();
        }

        return $this;
    }



    /*
        Удаление Docker образа по имени в формате ImageName:Version
        Если имя не указано, удаляется текущий актуальный образ
    */
    public function dockerImageDelete
    (
        string $AImage = '' /* Наименование образа в формате Name:Version */
    )
    {
        if( $this -> isOk() )
        {
            if( empty( $AIDImage ))
            {
                $AImage =  $this -> getImageBuild();
            }

            /* Выполнение удаления образа через shell */
            Shell::Create( $this -> getLog() )
            -> SetComment( 'Delete docker image' )
            -> Cmd
            (
                'docker rmi -f ' . $AImage,
                $this -> isTest()
            )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Экспорт докера в указанную папку
    */
    public function dockerImageExport
    (
        string  $AFolder    = '%IMAGES%',
        bool    $ALatest    = false
    )
    {
        if( $this -> isOk() )
        {
            $AFolder = empty( $AFolder ) ? '%IMAGES%' : $AFolder;
            $File = $this -> prep( $AFolder ) . '/' . $this -> getImageFile( 0, $ALatest );

            if( clCheckPath( dirname( $File )))
            {
                /* Export */
                Shell::create( $this -> getLog() )
                -> setComment( 'Export docker image' )
                -> cmd
                (
                    'docker save -o ' . $File . ' ' . $this -> getImageBuild(),
                    $this -> isTest()
                )
                -> resultTo( $this );
            }
        }
        return $this;
    }



    /*
        Сборка команды для запуска контейнера
    */
    public function imageRunCmd()
    {
            return
            $this -> prep
            (
                'docker run -itd --rm' .
                $this -> keyBeforeValue( ' -p ', $this -> getParam( 'ContainerPorts', [] )) . ' ' .
                (
                    (
                        !empty( $this -> getParam( 'HostFolder', [] )) &&
                        !empty( $this -> getParam( 'DockerFolder', [] ))
                    )
                    ?
                    (
                        ' -v "' . $this -> prep( $this -> getParam( 'HostFolder' )) . '"' .
                        ':' .
                        '"' . $this -> prep( $this -> getParam( 'DockerFolder' )) . '"'
                    )
                    :''
                ) . ' ' .
                $this -> keyBeforeValue( ' --cap-add ', $this -> getParam( 'RemoteCapabilities', [] )) . ' ' .
                $this -> getImageBuild()
            );
    }




    /*
        Деплой docker контейнера на удаленный хост в соответсвии с настройкамми
    */
    public function imageRunLine()
    {
        if( $this -> isOk() )
        {
            $this -> getLog() -> prn( $this -> imageRunCmd(), 'Docker run line' );
        }
        return $this;
    }



    /*
        Деплой docker контейнера на удаленный хост в соответсвии с настройкамми
    */
    public function imageDeploy
    (
    )
    {
        if( $this -> isOk() )
        {
            $RunCommand = $this -> imageRunCmd();

            $this
            /* Установка текущей версии образа */
            -> setParam( 'IMAGE_FILE_CURRENT', $this -> GetImageFile() )

            /* Перемещение файла образа на целевом инстансе */
            -> sync
            (
                $this -> prep( '%IMAGES%/%IMAGE_FILE_CURRENT%' ),
                $this -> prep( '%REMOTE%:%REMOTE_IMAGES%/' ),
                [],
                false,
                'Move the image to remote host',
                ! $this -> isFull()
            )

            /* Создание образа из файла на целевом инстансе */
            -> shell
            (
                [ 'docker load --input "%REMOTE_IMAGES%/%IMAGE_FILE_CURRENT%"' ],
                true,
                'Import the container on remote system'
            )

            /* Удаление файла образа на целевом инстансе */
            -> shell
            (
                [ 'rm %REMOTE_IMAGES%/%IMAGE_FILE_CURRENT%' ],
                true,
                'Remove docker file with image'
            )

            /* Остановка текущего докер образа на удаленном хосте */
            -> shell
            (
                [ 'docker stop \$(docker ps | grep %ImageName% | awk \'{print \$1}\')' ],
                true,
                'Stop docker container on remote',
                Result::RC_OK
            )

            -> shell
            (
                [ $RunCommand ],
                true,
                'Run new container on remote'
            )
            ;

            $FileRunContainer = $this -> prep( '%DEST%/container_run.sh' );
            file_put_contents( $FileRunContainer, $RunCommand );
            $this -> getLog() -> info( 'Docker image run command saved to file' ) -> param( 'File', $FileRunContainer );
        }
        return $this;
    }



    /*
        Удаление образов младше указанной версии от текущей
    */
    public function imagePurge
    (
        int $ADepth = 5,        /* Глубина версий на удаление */
        bool $ARemote = false   /* Выполнение на целевом инстансе */
    )
    {
        if( $this -> isOk() )
        {
            /* Получение версии и имени образа */
            $ImageName = $this -> getParam( 'ImageName' );
            $CurrentVersion = $this -> versionRead();

            /* Сборка перечня имеющихся версий с целевого инстанса */
            $Result =
            Shell::Create( $this -> getLog() )
            -> setConnection( $ARemote ? $this -> prep( '%REMOTE%' ) : '' )
            -> setPrivateKeyPath( $ARemote ? $this -> getParam( 'REMOTE_SSL_KEY' ) : '' )
            -> cmd( 'docker images ' . $ImageName, $this -> isTest() )
            -> getResult();

            /* Обход ответа перечня версий на предмет необходимости удаления */
            foreach( $Result  as $Line )
            {
                $Lexemes = preg_split( '/ {2,}/', $Line );
                if( count( $Lexemes ) > 2 && $Lexemes[ 0 ] == $ImageName )
                {
                    $Version = $this -> stringToVersion( $Lexemes[ 1 ] );
                    if
                    (
                        !empty( $Version ) &&
                        $Version[ 'Version' ] == $CurrentVersion[ 'Version' ] &&
                        $Version[ 'Build' ] <= $CurrentVersion[ 'Build' ] - $ADepth
                    )
                    {
                        $ImageForDelete = $this -> getImageName( $Version );
                        Shell::Create( $this -> getLog())
                        -> setConnection( $ARemote ? $this -> prep( '%REMOTE%' ) : '' )
                        -> setPrivateKeyPath( $ARemote ? $this -> getParam( 'REMOTE_SSL_KEY' ) : '' )
                        -> setComment( 'Purge image on remote host' )
                        -> cmd( 'docker rmi -f ' . $ImageForDelete, !$this -> isFull() );
                    }
                }
            }
        }
        return $this;
    }



    /*
        Установка тэга для образа для заливки в приватный репозиторий
    */
    public function imageTag
    (
        bool $ALatest = false
    )
    {
        if( $this -> isOk() )
        {
            Shell::create( $this -> getLog() )
            -> setComment( 'Image tagging' )
            -> cmd
            (
                $this -> prep
                (
                    'docker tag '.
                    (
                        $ALatest
                        ? $this -> GetImageLatest()
                        : $this -> getImageBuild()
                    ) .
                    ' ' .
                    '%Host%/' .
                    (
                        $ALatest
                        ? $this -> GetImageLatest()
                        : $this -> getImageBuild()
                    )
                ),
                $this -> isTest()
            )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Публикация текущего контейнера во внешнем ресурсе
    */
    public function imagePublic
    (
        bool $ALatest = false
    )
    {
        if( $this -> isOk() )
        {
            Shell::Create( $this -> getLog() )

            /* Publication */
            -> Cmd
            (
                $this -> prep
                (
                    'docker push ' .
                    (
                        $ALatest
                        ? $this -> GetImageLatest()
                        : $this -> GetImageBuild()
                    )
                ),
                $this -> isTest()
            )

            -> ResultTo( $this );
        }

        return $this;
    }



    /*
        Создание тома docker
        docker volume create —-name my_volume
    */
    public function volumeCreate
    (
        string $AName
    )
    {
        return $this;
    }



    /*
        Проверка сущестования тома по имены
        docker volume ls
    */
    public function volumeExists
    (
        string $AName
    )
    {
        return $this;
    }



    /*
        Возвращает перечень томов
        docker volume ls
    */
    public function volumeList()
    {
        return $this;
    }



    /*
        У тома по имены
        docker volume rm my_volume
    */
    public function volumeDelete
    (
        string $AName
    )
    {
        return $this;
    }



    /**************************************************************************
        Version
    */

    /*
        Возвращает имя образа с учетом версии
        ImageName:Version
    */
    public function getImageBuild
    (
        int $AShift = 0 /* Версия */
    )
    {
        return
        $this -> getParam( 'ImageName' ) .
        ':' .
        $this -> versionToString( $AShift );
    }



    /*
        Возвращает имя образа из переданной версии
        ImageName:Version
    */
    public function getImageName
    (
        array $AVersion = []
    )
    {
        return
        $this -> getParam( 'ImageName' ) .
        ':' .
        clValueFromObject( $AVersion, 'Version', 'alpha' ) .
        '.' .
        clValueFromObject( $AVersion, 'Build', 0 );
    }



    /*
        Возвращает имя образа с последней версией
        ImageName:latest
    */
    public function getImageLatest()
    {
        return $this -> getParam( 'ImageName' ) . ':latest';
    }



    /*
        Возвращает имя файла версии для задачи
    */
    public function getVersionFile()
    {
        return $this -> Prep( '%VERSION_FILE%' );
    }



    /*
        Чтение текущей версии
        Результат возвращается в именованном массиве
        [
            'Version',
            'Build'
        ]
    */
    public function versionRead()
    {
        $File = $this -> GetVersionFile();
        $Result = null;

        if( file_exists( $File ))
        {
            $Result = json_decode
            (
                @file_get_contents
                (
                    $this -> GetVersionFile()
                ),
                true
            );
        }

        if( empty( $Result ))
        {
            $Result =
            [
                'Version'   => 'alpha',
                'Build'     => 0
            ];
        }
        return $Result;
    }



    /*
        Запись версионного файла
    */
    public function versionWrite
    (
        /* Именованный массив для записи в файл */
        array $AVersion
    )
    {
        $Result = file_put_contents
        (
            $this -> getVersionFile(),
            json_encode( $AVersion )
        );
        return $this;
    }



    /*
        Представление версии как строки
    */
    public function versionToString
    (
        /* Сдвиг версии билда на указанный номер*/
        int     $AShift = 0
    )
    {
        $Version = $this -> versionRead();
        return $Version[ 'Version' ] . '.'
        . (string)( (int) $Version[ 'Build' ] + $AShift );
    }



    /*
        Конвертация строки в версию
    */
    public function stringToVersion
    (
        string $AValue = 'alpha.0'
    )
    {
        $Result = null;
        $Lexemes = explode( '.', $AValue );
        if( count( $Lexemes ) > 1 )
        {
            $Result[ 'Version' ] = $Lexemes[ 0 ];
            $Result[ 'Build' ] = ( int )$Lexemes[ 1 ];
        }
        return $Result;
    }



    /*
        Увеличение номера версии на единицу в файле
    */
    public function versionInc()
    {
        $Version = $this -> versionRead();
        $Version[ 'Build' ] = $Version[ 'Build' ] + 1;
        $this -> versionWrite( $Version );
        return $this;
    }



    /*
        Возвращает имя файла с указанной версией
    */
    public function getImageFile
    (
        $AShift = 0,
        $ALatest = false
    )
    {
        return str_replace
        (
            '/', '_',
            (
                $ALatest === true
                ? $this -> getImageLatest()
                : $this -> getImageBuild( $AShift )
            )
        )
        . '.tar';
    }



    /*************************************************************************
        Директивы
    */


    /*
        Выполняет подмену макроподстановок в строке. возвращается результат для
        которого все значения замененены Параметры заменяются с использованием
        открывающих изакрывающих ключей. Чувствительно к регистру
    */
    public function prep
    (
        /* Значение для макроподстановок */
        $ASource,
        /* Список игнорируемых ключеней, которые остаются в незменном виде */
        array $AExclude = [],
        /* Открывающий ключ макроподстановки. Только 1 символ */
        string $ABegin  = '%',
        /* Закрывающий ключ макроподстановки. Только 1 символ */
        string $AEnd    = '%'
    )
    {
        return  clPrep
        (
            $ASource,
            $this -> getParams(),
            $AExclude,
            $ABegin,
            $AEnd
        );
    }



    /*
        Рекурсивная подмена значений для файлового пути
        Содержимое файлов читается, производится замена, файл сохраняется
    */
    public function replace
    (
        /* Файловый путь для исполнения подмены */
        string $APath,
        /*
            Массив строк файловых масок при соответсвии которым выполнятся
            подмены значений
        */
        array $AIncludes    = null,
        /*
            Массив строк файловых масок при соответсвии которым выполнятся
            подмены значений
        */
        array $AExcludes    = null,
        /* Исключение ключей по именам */
        array $AExcludeKeys = null
    )
    {
        if( $this -> isOk() )
        {
            /* Заполнение массивов включения исключения */
            if( empty( $AIncludes ))    $AIncludes = [];
            if( empty( $AExcludes ))    $AExcludes = [];
            if( empty( $AExcludeKeys )) $AExcludeKeys = [];

            /* Подготовка параметров включаемых файлов */
            foreach( $AIncludes as &$Item )
            {
                $Item = $this -> prep( $Item );
            }

            /* Подготовка параметров исключаемых файлов*/
            foreach( $AExcludes as &$Item )
            {
                $Item = $this -> prep( $Item );
            }

            /* Подгтовка пути */
            $Path = $this -> prep( $APath );

            /* Получение списка занчений */
            $Values = $this -> getParams();

            $this -> getLog()
            -> begin( 'Replace' )
            -> param( 'Path' , $Path )
            -> param( 'Real path', realpath( $Path ));

            /* Статистика подмен */
            $Skip       = 0;    /* количество пропущенных при подмене */
            $Error      = 0;    /* количество с ошибками */
            $Replace    = 0;    /* количество выполненны подмен */
            $NoMatch    = 0;    /* подмен не обнаружено */

            /* Рекурсивное сканирование фалов */
            clFileScan
            (
                $Path,
                /* Нет обратного вызова для папок */
                null,
                /* Обратный вызов для файлов */
                function( $AFile, $APath )
                use
                (
                    $Values,
                    $AIncludes,
                    $AExcludes,
                    $AExcludeKeys,
                    &$Skip,
                    &$Error,
                    &$Replace,
                    &$NoMatch
                )
                {
                    $this -> getLog() -> trace();

                    /*
                        Проверка соответсвия имени файла условиям включающих и
                        исключающих масок
                    */
                    if( clFileMatch( $AFile, $AIncludes, $AExcludes ))
                    {
                        /* Загрузка файлов */
                        $Source = file_get_contents( $AFile );

                        /* Выполненеи подмен */
                        $Result = $this -> prep( $Source, $AExcludeKeys, '%', '%' );

                        /* Проверка результата */
                        $MD5Source = md5( $Source );
                        $MD5Result = md5( $Result );

                        if( $MD5Source == $MD5Result )
                        {
                            /* Подмен не обнаружено */
                            $this -> getLog() -> text( '[-]', Log::COLOR_INFO );
                            $NoMatch++;
                        }
                        else
                        {
                            /* Подмена произведена */
                            $this -> GetLog() -> Text( '[r]', Console::ESC_INK_LIME );
                            $Replace++;
                            /* Сохранение результатов */
                            if( file_put_contents( $AFile, $Result ) === false )
                            {
                                /* Сообщение об ошибке в случае несохранения файла */
                                $this -> getLog() -> Text( '[X]', Log::COLOR_ERROR );
                                $Error++;
                            }
                        }
                    }
                    else
                    {
                        /* Сообщение в лог при пропуске файла на основании включающих исключающих масок */
                        $this -> getLog() -> text( '[s]',  Log::COLOR_INFO );
                        $Skip++;
                    }
                    $this -> getLog() -> param( 'File', $AFile );
                }
            );

            if( ( $Error ) > 0 )
            {
                $this -> setResult( 'ErrorReplaceInFile', [ 'Count' => $Error ] );
            }

            $this -> GetLog()
            -> info( 'Statistic' )
            -> param( '[s]Skiped'      , $Skip )
            -> param( '[-]No mathch'   , $NoMatch )
            -> param( '[r]Replaced'    , $Replace )
            -> param( '[X]Error'       , $Error )
            -> end();
        }
        return $this;
    }



    /*
        Рекурсивная проверка корректности исходного кода
    */
    public function correctness
    (
        /* Файловый путь для начала проверки коректности */
        string $AStartPath,
        /*
            Включающий массив файловых масок, при соответсвии которым выполнятся
            проверка корректности
        */
        array $AIncludes    = null,
        /*
            Исключающий массив файловых масок, при соответсвии которым не
            выполнятся проверка корректности
        */
        array $AExcludes    = null,
        bool $ACache        = true
    )
    {
        if( $this -> isOk() )
        {
            /* Статистика */
            $Errors     = [];
            $Skiped     = 0;
            $Error      = 0;
            $NoChanges  = 0;
            $Passed     = 0;

            /* Заполнение массивов включения исключения */
            if( empty( $AIncludes ))    $AIncludes = [];
            if( empty( $AExcludes ))    $AExcludes = [];

            /* Подготовка параметров включаемых файлов */
            foreach( $AIncludes as &$Item )
            {
                $Item = $this -> prep( $Item );
            }

            /* Подготовка параметров исключаемых файлов*/
            foreach( $AExcludes as &$Item )
            {
                $Item = $this -> prep( $Item );
            }

            /* Подгтовка пути источника */
            $Path = $this -> prep( $AStartPath );

            /* Подготовка пути */
            $StablePath = $this -> prep( '%CACHE_STABLE%' );

            $this -> getLog()
            -> begin( 'Correctness' )
            -> param( 'Path' , $Path );

            /* Рекурсивное сканирование фалов */
            clFileScan
            (
                $Path,
                /* Нет обратного вызова для папок */
                null,
                /* Обратный вызов для файлов */
                function( $AFile, $APath )
                use
                (
                    $StablePath,
                    $AIncludes,
                    $AExcludes,
                    $ACache,
                    $Path,
                    &$Errors,
                    &$Skiped,
                    &$Error,
                    &$NoChanges,
                    &$Passed
                )
                {
                    $this -> getLog() -> trace();

                    /* Получаем расширение */
                    $Ext = strtolower( pathinfo( $AFile, PATHINFO_EXTENSION ));

                    /* Проверка соответсвия имени файла условиям включающих и исключающих масок*/
                    if
                    (
                        clFileMatch( $AFile, $AIncludes, $AExcludes ) &&
                        ( $Ext == 'md' || $Ext == 'php' )
                    )
                    {
                        /* Загрузка файла */
                        $OriginalSource = file_get_contents( $AFile );
                        $Source = $OriginalSource;

                        /* Расчет md5 кэша */
                        $MD5Source = md5( $Source );

                        /* Файл для хранения кэша */
                        $CacheFile = $this -> prep( '%CACHE_STABLE%' ) . clScatterName( md5( $AFile ) );
                        $this -> checkPath( dirname( $CacheFile ));

                        /* Чтение последнего кэша для файла */
                        $MD5LastCache = file_exists( $CacheFile ) ? file_get_contents( $CacheFile ) : null;

                        if( !$ACache || $MD5LastCache != $MD5Source )
                        {
                            if( $this -> correctnessFile( $AFile, $Source, $Path ))
                            {
                                /* Корректность проверенна */
                                file_put_contents( $CacheFile, $MD5Source );
                                $this -> getLog() -> text( '[+]', Console::ESC_INK_LIME );
                                $Passed++;
                                if( $OriginalSource != $Source )
                                {
                                    /* Запись файла */
                                    file_put_contents( $AFile, $Source );
                                }
                            }
                            else
                            {
                                /* Ошибка корректности */
                                $this -> getLog() -> text( '[x]', Log::COLOR_ERROR );
                                array_push( $Errors, $AFile );
                            }
                        }
                        else
                        {
                            $this -> getLog() -> text( '[-]', Log::COLOR_INFO );
                            $NoChanges++;
                        }
                    }
                    else
                    {
                        /* Сообщение в лог при пропуске файла на основании включающих сключающих масок */
                        $this -> getLog() -> text( '[s]',  Log::COLOR_INFO );
                        $Skiped++;
                    }
                    $this -> getLog() -> param( 'File', $AFile );
                }
            );

            if( count( $Errors) > 0 )
            {
                $this -> setResult( 'ErrorCorrectnessCheck', [ 'Count' => count( $Errors ) ] );
            }

            $this -> getLog()
            -> paramLine( 'Skiped [s]'      , $Skiped )
            -> paramLine( 'No changes [-]'  , $NoChanges )
            -> paramLine( 'Passed [+]'      , $Passed )
            -> paramLine( 'Error [X]'       , count( $Errors ) )
            -> dump( $Errors, 'Errors' )
            -> end();
        }
        return $this;
    }



    /*
        Запуск cli на локальном или удаленном хосте
        Используемые параметры деплоера:
            REMOTE          Удаленный хост и пользователь для SSH подключения
            REMOTE_SSL_KEY  файл ключ SSL для доступа через SSH
    */
    public function shell
    (
        /* Перечень командных линий для исполнения */
        array   $ALines,
        /* Удаленное исполнение */
        bool    $ARemote        = false,
        /* Коментарий исполнения */
        string  $AComment       = '',
        /* Код результата, который будет возвращен в любом случае */
        string  $AResultCode    = ''
    )
    {
        if( $this -> isOk() )
        {
            $Shell = Shell::Create( $this -> GetLog() );

            $Shell
            -> CmdBegin()
            -> SetComment( $AComment )
            ;

            if( $ARemote )
            {
                $Shell
                -> setConnection( $this -> prep( '%REMOTE%' ))
                -> setPrivateKeyPath( $this -> getParam( 'REMOTE_SSL_KEY' ));
            }

            /* Обрабатываем линии */
            $Lines = [];
            foreach( $ALines as $Line)
            {
                array_push( $Lines, $this -> Prep( $Line ) );
            }

            $Shell
            -> cmdAdd( implode( ' && ', $Lines) )
            /* Запуск команды. Тестовый режим проверяется для удаленного
            исполнителя и для локального */
            -> cmdEnd( ' ', $ARemote ? ! $this -> isFull() : $this -> isTest() );

            if( !empty( $AResultCode ))
            {
                $this -> setCode( $AResultCode );
            }
            else
            {
                $Shell -> resultTo( $this );
            }
        }
        return $this;
    }



    /*
        Проверка, включен ли тестовый режим в параметре Mode=Test.
    */
    public function isTest()
    {
        $Result = true;
        switch( $this -> getMode() )
        {
            case self::MODE_FULL:
            case self::MODE_BUILD:
                $Result = false;
            break;
        }
        return $Result;
    }



    /*
        Проверка, включен ли полный режим режим в параметре mode=Full.
    */
    public function isFull()
    {
        return
        $this -> getMode() == self::MODE_FULL;
    }



    /*
        Вывод параметров в лог
    */
    public function dumpParams()
    {
        $this -> getLog() -> begin( 'Dump parameters' );
        foreach( $this -> getParams() as $Key => $Value )
        {
            $this -> getLog() -> paramLine( $Key, $this -> prep( $Value ));
        }
        $this -> getLog() -> end();
        return $this;
    }



    /*
        Возвращает строковое значение (ключи) перед каждым элементом массива
        Пример:
            KeyBeforeValue( ' -key ', [ 'asd', 'dfg', 'dfg' ] )
            '-key asd -key dfg -key dfg'
    */
    static private function keyBeforeValue
    (
        string  $AKey,          /* Ключ */
        array   $AArray = []    /* Массив значений */
    )
    {
        return empty( $AArray ) ? '' : $AKey . implode( $AKey, $AArray );
    }



    /*
        Пурификация - удаляем папки предыдущих билдов
    */
    public function purifyDestination()
    {
        return
        $this -> delete( '%DEST%', 'Remove destination folder' );
    }



    /*
        Проверка файла на корректность
    */
    private function correctnessFile
    (
        string $AFile,          /* Файл */
        string &$AContent,      /* Содержимое */
        string $ARoot           /* Корень относительно которого проверяется */
    )
    {
        $Result = true;
        switch( pathinfo( $AFile, PATHINFO_EXTENSION ))
        {
            case 'php'  : $Result = $this -> correctnessPHP( $AFile, $AContent, $ARoot ); break;
        }
        return $Result;
    }



    /*
        Проверка корректности PHP файла
        Выполняется проверка синтаксиса
    */
    private function correctnessPHP
    (
        string $AFile
    )
    {
        return Shell::Create()
        -> cmdAdd( 'php -l ' )
        -> fileAdd( $AFile )
        -> cmdEnd()
        -> isOk();
    }



    /*
        Вывод информации в консоль
    */
    public function info
    (
        $AValue
    )
    {
        if( $this -> isOk() )
        {
            $this -> getLog()
            -> info( $this -> prep( $AValue ));
        }
        return $this;
    }




    /**************************************************************************
        Fob

        Файл специфических настроек, содеражщий пароли и иные чувствительные
        данные. Файл должен лежать отдельно от проекта и подключаятся при сборке
        путем установки аргумента %FOB_FILE%
    */

    public function activateFob
    (
        string $aValue
    )
    {
        $json = json_decode
        (
            file_get_contents( $this -> prep( '%FOB_FILE%' ) )
        );

        $this -> activeFob = clValueFromObject
        (
            empty( $json ) ? [] : $json, $aValue, []
        );

        if( empty( $this -> activeFob ))
        {
            $this -> setResult( 'FobIsEmpty', [ 'Name' => $AValue ] );
        }

        return $this;
    }



    /*
        Returns the active fob
    */
    public function getActiveFob()
    {
        return $this -> ActiveFob;
    }

        public function getMode()
    {
        return $this -> Mode;
    }




    /**************************************************************************
        Setters and getters
    */


    public function setMode
    (
        string $AMode
    )
    {
        $this -> Mode = $AMode;
        return $this;
    }



    /*
        SSH connection settings for the target host used for deployment.
        Attributes are used in the following methods"
            shell
    */
    public function setRemote
    (
        string $aUser,
        string $aHost,
        string $aPort
    )
    {
        return $this -> AddParam
        ([
            /* Логин для ssh доступа к целевому хосут */
            'REMOTE_USER'   => $aUser,
            /* Адрес целевого хоста, в данном случае выкатываем на локалхост */
            'REMOTE_HOST'   => $aHost,
            /* Порт целевого хоста */
            'ROMOTE_PORT'   => $aPort
        ]);
    }
}
