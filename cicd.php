<?php
/*
    Catlair PHP Copyright (C) 2021 https://itserv.ru

    This program (or part of program) is free software: you can redistribute
    it and/or modify it under the terms of the GNU Aferro General
    Public License as published by the Free Software Foundation,
    either version 3 of the License, or (at your option) any later version.

    This program (or part of program) is distributed in the hope that
    it will be useful, but WITHOUT ANY WARRANTY; without even the implied
    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the GNU Aferro General Public License for more details.
    You should have received a copy of the GNU Aferror General Public License
    along with this program. If not, see <https://www.gnu.org/licenses/>.
*/

/*
    Refactoring from pusa.dev https://gitlab.com/catlair/pusa/-/tree/main
*/

/*
    CICD functionality module
    Includes:
        - working with GIT
        - docker builds
        - shell operations on local and remote hosts via SSH
        - bulk replacements
*/



namespace catlair;



/*
    Local libraries
*/
require_once LIB . '/core/shell.php';
require_once LIB . '/core/utils.php';
require_once LIB . '/core/moment.php';
require_once LIB . '/app/hub.php';



/*
    Cici class
*/
class Cicd extends Hub
{
    /*
        Modes
    */
    /* Testing mode тo actions are performed, only logging */
    const MODE_TEST     = 'test';
    /* Build mode оnly actions on the local instance are performed */
    const MODE_BUILD    = 'build';
    /* Full build and deployment of the image to the target instance */
    const MODE_FULL     = 'full';

    /* Default paremeters */
    const DEFAULT_PARAMS =
    [
        /*
            Parameters
        */
        'root'              => ROOT,
        /* Define build path */
        'dest'              => '%root%/rw/private/dest',
        /* Source folder for main repository */
        'source'            => '%root%/ro/private/source',
        /* Строка запуска докера */
        'docker-run'        => 'docker run -itd --rm --name=%project%-%instance% %ports% %volumes% %running-image%',
        /* Строка остановки докера, если не запущен ошибки нет */
        'docker-stop'       => 'docker ps -q --filter name=%project%-%instance% | xargs -r docker stop --time=1',
        /* Volums */
        'docker-volumes'    => []
    ];



    /*
        Git files
    */
    const GIT_FILES =
    [
        '.git',
        '.gitignore',
        'README.md',
        'pull.sh',
        'push.sh',
        '*.md'
    ];



    /* Current mode */
    private $mode       = self::MODE_TEST;
    /* Current fon (empty) */
    private $activeFob  = [];



    /**************************************************************************
        Build preparation must be invoked before execution
    */
    public function begin
    (
        string $aMode
    )
    {
        $this -> mode = $aMode;

        return $this
        /* Adding static default parameters */
        -> addParams( self::DEFAULT_PARAMS, true )
        /* Adding dynamic parameters */
        -> addParams
        (
            [
                /* Let current user */
                'user' => get_current_user(),
                /* Key fob file with security parameters */
                'fob-file' =>
                clValueFromObject( $_SERVER, 'HOME' ) . '/fob.json',
                /* List of files exclude for gitSync and gitPurge */
                'git-exclude' => self::GIT_FILES,
                /* Получение версии */
                'product-version' => $this -> versionToString( 1 )
            ],
            true
        )
        -> dumpParams()
        ;
    }


    public function ciNow()
    {
        return $this -> addParams
        ([
            'ci-moment' => Moment::Create() -> now() -> toStringODBC()
        ]);
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
        /* Git ba*/
        int $aDepth         = 1,
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
            -> cmdAdd( empty( $aBranch ) ? '' : '-b ' . $aBranch )
            -> cmdAdd( '--depth ' . $aDepth )
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
        /* Optional comment for logging */
        string $aComment = '',
        /* File path where deletion is performed */
        string $aSource
    )
    {
        if( $this -> isOk())
        {
            foreach( $this -> getParam([ 'git-exclude' ], []) as $item )
            {
                $this -> delete( $aSource . '/' . $item, 'Git purge' );
            }
        }
        return $this;
    }



    /*
        Git pure cleanup:
        - hard reset of all changes
        - delete untracked files and folders
    */
    public function gitPure
    (
        /* Optional comment for logging */
        string $aComment = '',
        /* Destination path to clean up */
        string $aSource = '%build%/result'
    )
    {
        if( $this -> isOk())
        {
            $source = $this -> prep( $aSource );
            $currentPath = getcwd();
            $this -> changeFolder( $source );

            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git reset --hard' )
            -> cmdAdd( '&&' )
            -> cmdAdd( 'git clean -fdx' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            /* Restore current folder */
            chdir( $currentPath );
        }
        return $this;
    }



    /*
        Git pull
    */
    public function gitPull
    (
        /* Optional comment for logging */
        string $aComment = '',
        /* Destination path for pulling */
        string $aSource = '%build%/result'
    )
    {
        if( $this -> isOk())
        {
            $source = $this -> prep( $aSource );
            $currentPath = getcwd();
            $this -> changeFolder( $source );

            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git pull' )
            -> cmdAdd( '--rebase' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
            /* Restore current folder */
            chdir( $currentPath );
        }
        return $this;
    }



    /*
        Git add command
    */
    public function gitAdd
    (
        /* File path to the repository */
        string $aComment,
        /* Optional comment for logging */
        string $aSource = '%build%/result'
    )
    {
        if( $this -> isOk())
        {
            $source = $this -> prep( $aSource );
            $currentPath = getcwd();
            $this -> changeFolder( $source );

            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git add -A' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            chdir( $currentPath );
        }
        return $this;
    }


    /*
        Git commit
    */
    public function gitCommit
    (
        /* File path to the repository */
        string $aComment,
        /* Optional comment for logging */
        string $aSource = '%build%/result',
        /* Comment for commit */
        string $aCommitComment = 'autocommit'
    )
    {
        if( $this -> isOk())
        {
            $source = $this -> prep( $aSource );
            $currentPath = getcwd();
            $this -> changeFolder( $source );

            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git diff --cached --quiet || git commit -m ' . '"' . $aCommitComment . '"' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this )
            ;

            chdir( $currentPath );
        }
        return $this;
    }



    /*
        Performs a push to the current branch of the repository
    */
    public function gitPush
    (
        /* File path to the repository */
        string $aComment,
        /* Optional comment for logging */
        string $aSource = '%build%/result'
    )
    {
        if( $this -> isOk())
        {
            $source = $this -> prep( $aSource );
            $currentPath = getcwd();
            $this -> changeFolder( $source );

            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'git push' )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            chdir( $currentPath );
        }
        return $this;
    }



    /*
        Git checkout to specific branch.
        Force reset local branch from origin.
    */
    public function gitCheckout
    (
        /* Path to repo */
        string $aPath,
        /* Branch to checkout */
        string $aBranch
    )
    {
        $path = $this -> prep( $aPath );
        $currentPath = getcwd();
        $this -> changeFolder( $path );

        Shell::create( $this -> getLog() )
        -> cmdBegin()
        -> cmdAdd( 'git fetch origin' )
        -> cmdAdd( '&&' )
        -> cmdAdd( 'git checkout -B ' . $aBranch . ' origin/' . $aBranch )
        -> cmdEnd( ' ', $this -> isTest() )
        -> resultTo( $this );

        chdir( $currentPath );
        return $this;
    }



    /*
        Clones the repository or pulls if it already exists
        Warning - all uncommited changes will be lost
    */
    public function gitUse
    (
        /* Optional comment for cloning log */
        string  $aComment,
        /* Repository source for cloning */
        string  $aSource,
        /* Destination path for cloning */
        string  $aDest,
        /* Optional branch for cloning */
        string  $aBranch     = '',
        /* Git history depth */
        int     $aDepth      = 1
    )
    {
        if( file_exists( $this -> prep( $aDest . '/.git' )))
        {
            $this -> gitPure( $aComment, $aDest );
            if( $aBranch )
            {
                $this -> gitCheckout( $aDest, $aBranch );
            }
            else
            {
                $this -> gitPull( $aComment, $aDest );
            }
        }
        else
        {
            $this -> gitClone( $aSource, $aDest, $aBranch, $aDepth, $aComment);
        }
        return $this;
    }



    /*
        Upload git repo
        add + commit + pull + push
    */
    public function gitUpload
    (
        /* Optional comment for cloning log */
        string  $aComment,
        /* Repository source for cloning */
        string  $aSource = '%build%/result',
        /* Comment for commit */
        string  $aCommitComment = 'autocommit'
    )
    {
        return $this
        -> gitAdd( 'git add: ' . $aComment, $aSource )
        -> gitCommit( 'git commit: ' . $aComment, $aSource, $aCommitComment )
        -> gitPull( 'git pull: ' . $aComment, $aSource )
        -> gitPush( 'git push: ' . $aComment, $aSource )
        ;
    }




    /**************************************************************************
        File system work
    */


    /*
        Change current folder
    */
    public function changeFolder
    (
        /* Path */
        string $aFolder = '%BUILD%'
    )
    {
        if( $this -> isOk() )
        {
            $folder = $this -> prep( $aFolder );
            $this
            -> getLog()
            -> trace( 'Change folder' )
            -> param( 'Folder', $folder );

            if( file_exists( $folder ) && is_dir( $folder ))
            {
                chdir( $folder );
            }
            else
            {
                $this -> setResult
                (
                    'folder-not-exists',
                    [ 'Folder' => $folder ]
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
        array   $aPathes        = [],
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
        Recursive file copying
    */
    public function sync
    (
        /* comment for output during command execution */
        string $aComment = '',
        /* file path source for copying */
        string $aSource,
        /* file path destination for copying */
        string $aDestination,
        /* array of string masks to exclude during copying */
        array $aExcludes = [],
        /* allow deletion of files in destination that are not present in source */
        bool $aDelete = true,
        /* allow deletion of files in destination that excluded */
        bool $aDeleteExcluded = true
    )
    {
        if( $this -> isOk() )
        {
            $this -> GetLog() -> Begin( 'Syncronize' ) -> Text( ' ' . $aComment );

            /* Конверсия путей в полные */
            $source = $this -> prep( $aSource );
            $source .= ( is_dir( $source ) ? '/' : '' );
            $destination = $this -> prep( $aDestination );

            $this -> GetLog()
            -> Trace() -> Param( 'Source', $source )
            -> Trace() -> Param( 'Destination', $destination );

            /* Проверка существования источника */
            if( ! $this -> isTest() )
            {
                $this -> checkPath( $aDestination );
            }

            if( $this -> isOk() )
            {
                $shell = Shell::create( $this -> getLog() );

                $shell
                -> cmdBegin()
                -> cmdAdd( 'rsync' )
                -> cmdAdd( '-azog' );

                /* Добавление исключений для синхронизации */
                if( !empty( $aExcludes ))
                {
                    foreach( $aExcludes as $exclude )
                    {
                        $shell -> longAdd
                        (
                            'exclude',
                            $this -> prep( $exclude )
                        );
                    }
                }

                if( $aDelete )
                {
                    $shell -> cmdAdd( '--delete' );
                }

                if( $aDeleteExcluded )
                {
                    $shell -> cmdAdd( '--delete-excluded' );
                }

                $shell
                -> fileAdd( $source )
                -> fileAdd( $destination )
                -> cmdEnd( ' ', $this -> isTest() )
                -> resultTo( $this );
            }
            $this -> getLog() -> end();
        }
        return $this;
    }


    /*
        Recursive file copying
    */
    public function syncPack
    (
        /* comment for output during command execution */
        string $aComment = '',
        /* file path source for copying */
        array $aSourceDestination,
        /* array of string masks to exclude during copying */
        array $aExcludes = [],
        /* allow deletion of files in destination that are not present in source */
        bool $aDelete = true,
        /* allow deletion of files in destination that excluded */
        bool $aDeleteExcluded = true
    )
    {
        foreach( $aSourceDestination as $source => $dest )
        {
            $this -> sync
            (
                $aComment,
                $source,
                $dest,
                $aExcludes,
                $aDelete,
                $aDeleteExcluded
            );
        }
        return $this;
    }




    /*
        Recurcive folders removeal
    */
    public function delete
    (
        string $aPath,
        string $aComment = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'rm' )
            -> cmdAdd( '-rf' )
            -> fileAdd( $this -> prep( $aPath ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }




    /*
        Move folder
    */
    public function move
    (
        string $aSource,
        string $aDest,
        string $aComment = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'mv' )
            -> fileAdd( $this -> prep( $aSource ) )
            -> fileAdd( $this -> prep( $aDest ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Copy folder
    */
    public function copyFolder
    (
        string $aSource,
        string $aDest,
        string $aComment = ''
    )
    {
        if( $this -> isOk())
        {
            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> cmdBegin()
            -> cmdAdd( 'cp -aT' )
            -> fileAdd( $this -> prep( $aSource ) )
            -> fileAdd( $this -> prep( $aDest ) )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Recursive creation of file paths
    */
    public function checkPath
    (
        /* File path to create */
        string $aPath
    )
    {
        if( $this -> isOk())
        {
            /* Prepare path */
            $path = $this -> prep( $aPath );
            $cmd = $this -> parseCLI( $path );

            if( $cmd[ 'Remote' ])
            {
                /*
                    Executes folder creation on a remote host
                    without checking the result
                */
                Shell::create( $this -> getLog() )
                -> setConnection( $cmd[ 'Connection' ] )
                -> setPrivateKeyPath( $this -> getParam( 'remote-ssl-key' ))
                -> cmd( 'mkdir -p ' . $cmd[ 'Path' ], ! $this -> isFull() )
                -> getResult();
            }
            else
            {
                /*
                    Checks for folder existence on the local host
                    and creates it if missing
                */
                if
                (
                    !$this -> isTest() &&
                    !clCheckPath( $this -> prep( $aPath ))
                )
                {
                    $this -> setResult
                    (
                        'DirectoryCheckError',
                        [ 'Path' => $aPath ]
                    );
                }
            }
        }
        return $this;
    }



    /*
        Parses a CLI string into components.
        The result is an array containing:
            bool Remote - flag indicating if the string contains login and host
                          for remote connection
            string  Connection - the login@host part if present
            string  Login - login for connection
            string  Host - host node for connection
            string  Path - path (always returned)
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
        Docker containers
    */

    /*
        Docker login
        Uses a keychain activateFob during login
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
                    'docker login %Host% '.
                    '--username %Login% '.
                    '--password-stdin '.
                    '< %PasswordFile%'
                ),
                $this -> isTest()
            )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Build conatinr
    */
    public function dockerImageBuild()
    {
        if( $this -> isOk() )
        {
            $this -> getLog()
            -> begin( 'Image build' )
            -> param( 'Current version', $this -> versionToString( 0 ))
            -> param( 'Next version', $this -> versionToString( 1 ))
            ;

            /* Building container */
            $Shell = Shell::create( $this -> GetLog() )
            -> setComment( 'Build the docker image' )
            -> cmdBegin()
            -> cmdAdd( 'cd "' . $this -> prep( '%dest%' ) . '";' )
            -> cmdAdd
            (
                'DOCKER_BUILDKIT=0 docker build -t ' .
                $this -> getImageBuild( +1 ) .
                ' -f ' .
                $this -> prep( '%dest%/Dockerfile' ) .
                ' . '
            )
            -> cmdEnd( ' ', $this -> isTest() )
            -> resultTo( $this );

            /* Set container id if building success */
            if( $this -> isOk() && !$this -> isTest() )
            {
                $this -> ImageID =  $Shell -> getResultByKey
                (
                    'Successfully built'
                );
                if( empty( $this -> ImageID ))
                {
                    $this -> setResult
                    (
                        'IDImageNotFound',
                        $this -> getImageBuild( +1 )
                    );
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
        Delete a Docker image by name in the format ImageName:Version.
        If no name is provided, the current active image is deleted.
    */
    public function dockerImageDelete
    (
        /* Image name in the format Name:Version */
        string $aImage = ''
    )
    {
        if( $this -> isOk() )
        {
            if( empty( $aImage ))
            {
                $aImage =  $this -> getImageBuild();
            }

            /* Executing image removal via shell */
            Shell::create( $this -> getLog() )
            -> SetComment( 'Delete docker image' )
            -> Cmd
            (
                'docker rmi -f ' . $aImage,
                $this -> isTest()
            )
            -> resultTo( $this );
        }
        return $this;
    }



    /*
        Exporting Docker to the specified folder
    */
    public function dockerImageExport
    (
        string  $aFolder    = '%IMAGES%',
        bool    $aLatest    = false
    )
    {
        if( $this -> isOk() )
        {
            $aFolder = empty( $aFolder ) ? '%IMAGES%' : $aFolder;

            $file = $this -> prep( $aFolder ) .
            '/' .
            $this -> getImageFile( 0, $aLatest );

            if( clCheckPath( dirname( $file )))
            {
                /* Export */
                Shell::create( $this -> getLog() )
                -> setComment( 'Export docker image' )
                -> cmd
                (
                    'docker save -o ' . $file . ' ' . $this -> getImageBuild(),
                    $this -> isTest()
                )
                -> resultTo( $this );
            }
        }
        return $this;
    }



    /*
        Constructing the command to run the container
    */
    public function imageRunCmd
    (
        $aInstance
    )
    {
        return $this -> prepParam
        (
            'docker-run',
            [
                'ports' => $this -> keyBeforeValue
                (
                    ' -p ',
                    $this -> keyValueToValue
                    (
                        $this -> getParam( 'docker-ports', [] ),
                        ':'
                    )
                ),
                'volumes' => $this -> buildDockerVolumes( $aInstance ),
                'running-image' => $this -> getImageBuild(),
                /* Указываем запускаемый инстанс */
                'instance' => $aInstance
            ]
        );
    }




    /*
        Остановка текущих инстансов докер образа
    */
    public function imageStop
    (
        string $aInstances
    )
    {
        if( $this -> isOk() )
        {
            $this -> getLog() -> begin( 'Docker images stoping' );
            $cmd = [];
            $list = explode( ',', $aInstances );
            foreach( $list as $instance )
            {
                $this -> shell
                (
                    [
                        $this -> prepParam
                        (
                            'docker-stop',
                            [
                                'instance' => $instance
                            ]
                        )
                    ],
                    'Docker stop'
                );
            }
            $this -> getLog() -> end();
        }
        return $this;
    }



    /*
        Остановка текущих инстансов докер образа
    */
    public function imageStart
    (
        string $aInstances
    )
    {
        if( $this -> isOk() )
        {
            $this -> getLog() -> begin( 'Docker images starting' );

            $cmd = [];

            $list = array_map('trim', explode( ',', $aInstances ));
            foreach( $list as $instance )
            {
                $this -> shell
                (
                    [
                        $this -> imageRunCmd( $instance )
                    ],
                    'Docker start'
                );
            }
            $this -> getLog() -> end();
        }
        return $this;
    }





    /*
        Deployment of a Docker container to a remote host according to the
        settings
    */
    public function imageDeploy
    (
        string $aInstances
    )
    {
        if( $this -> isOk() )
        {
            $RunCommand = $this -> imageRunCmd();

            $this
            /* Установка текущей версии образа */
            -> setParam
            (
                'IMAGE_FILE_CURRENT',
                $this -> GetImageFile()
            )

            /* Перемещение файла образа на целевой инстанс */
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
                [
                    'docker load ' .
                    '--input "%REMOTE_IMAGES%/%IMAGE_FILE_CURRENT%"'
                ],
                'Import the container on remote system'
            )

            /* Удаление файла образа на целевом инстансе */
            -> shell
            (
                [ 'rm %REMOTE_IMAGES%/%IMAGE_FILE_CURRENT%' ],
                'Remove docker file with image'
            )

            /* Остановка текущего докер образа на удаленном хосте */
            -> shell
            (
                [
                    $this -> prep
                    (
                        'docker stop \$(docker ps | grep %image-name% | awk \'{print \$1}\')'
                    )
                ],
                'Stop docker container on remote',
                Result::RC_OK
            )

            -> shell
            (
                [ $RunCommand ],
                'Run new container on remote'
            )
            ;

            $FileRunContainer = $this -> prep( '%dest%/container_run.sh' );
            file_put_contents( $FileRunContainer, $RunCommand );

            $this
            -> getLog()
            -> info( 'Docker image run command saved to file' )
            -> param( 'File', $FileRunContainer );
        }
        return $this;
    }



    /*
        Deletion of images older than the specified version from the current one
    */
    public function imagePurge
    (
        /* Комментарий при исполении */
        string $aComment,
        /* Глубина версий на удаление */
        int $ADepth = 5,
        /* Выполнение на целевом инстансе */
        bool $aRemote = false
    )
    {
        if( $this -> isOk() )
        {
            /* Получение версии и имени образа */
            $ImageName = $this -> prepParam( 'image-name' );
            $CurrentVersion = $this -> versionRead();

            /* Сборка перечня имеющихся версий с целевого инстанса */
            $Result =
            Shell::create( $this -> getLog() )
            -> setComment( $aComment )
            -> setConnection( $aRemote ? $this -> prep( '%remote%' ) : '' )
            -> setPrivateKeyPath( $aRemote ? $this -> getParam( 'remote-ssl-key' ) : '' )
            -> cmd( 'docker images ' . $ImageName, $this -> isTest() )
            -> getResult();

            /* Обход ответа перечня версий на предмет необходимости удаления */
            foreach( $Result  as $Line )
            {
                $lexemes = preg_split( '/ {2,}/', $Line );
                if( count( $lexemes ) > 2 && $lexemes[ 0 ] == $ImageName )
                {
                    $version = $this -> stringToVersion( $lexemes[ 1 ] );
                    if
                    (
                        !empty( $version ) &&
                        $version[ 'Version' ] == $CurrentVersion[ 'Version' ] &&
                        $version[ 'Build' ] <= $CurrentVersion[ 'Build' ] - $ADepth
                    )
                    {
                        $ImageForDelete = $this -> getImageName( $version );
                        Shell::create( $this -> getLog())
                        -> setConnection( $aRemote ? $this -> prep( '%remote%' ) : '' )
                        -> setPrivateKeyPath( $aRemote ? $this -> getParam( 'remote-ssl-key' ) : '' )
                        -> setComment( 'Purge image on remote host' )
                        -> cmd( 'docker rmi -f ' . $ImageForDelete, !$this -> isFull() );
                    }
                }
            }
        }
        return $this;
    }



    /*
        Setting a tag for the image to be pushed to a private repository
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
        Publishing the current container to an external resource
    */
    public function imagePublic
    (
        bool $ALatest = false
    )
    {
        if( $this -> isOk() )
        {
            Shell::create( $this -> getLog() )

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
        Creating a Docker volume
        docker volume create --name my_volume
    */
    public function volumeCreate
    (
        string $AName
    )
    {
        return $this;
    }



    /*
        Checking if a volume exists by name
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
        Returns a list of volumes
        docker volume ls
    */
    public function volumeList()
    {
        return $this;
    }



    /*
        Remove volume by name
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
        Image version
    */

    /*
        Returns image name with version
        ImageName:Version
    */
    public function getImageBuild
    (
        /* Version shift 0 - for current version */
        int $aShift = 0
    )
    {
        return
        $this -> prepParam( 'image-name' ) .
        ':' .
        $this -> versionToString( $aShift );
    }



    /*
        Returns the image name for the given version
        ImageName:Version
    */
    public function getImageName
    (
        array $aVersion = []
    )
    {
        return
        $this -> prepParam( 'image-name' ) .
        ':' .
        clValueFromObject( $aVersion, 'Version', 'alpha' ) .
        '.' .
        clValueFromObject( $aVersion, 'Build', 0 );
    }



    /*
        Returns the image name with the latest version
        ImageName:latest
    */
    public function getImageLatest()
    {
        return $this -> getParam( 'ImageName' ) . ':latest';
    }



    /*
        Returns the filename for the version of the task
    */
    public function getVersionFile()
    {
        return $this -> prep( '%version-file%' );
    }



    /*
        Reading the current version
        The result is returned as an associative array
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
        Writing the version file
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
        Representing the version as a string
    */
    public function versionToString
    (
        /* Increment build version by the specified number */
        int     $AShift = 0
    )
    {
        $Version = $this -> versionRead();
        return $Version[ 'Version' ] . '.'
        . (string)( (int) $Version[ 'Build' ] + $AShift );
    }



    /*
        Conversion of a string to a version
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
        Incrementing the version number by one in the file
    */
    public function versionInc()
    {
        $Version = $this -> versionRead();
        $Version[ 'Build' ] = $Version[ 'Build' ] + 1;
        $this -> versionWrite( $Version );
        return $this;
    }



    /*
        Returns the filename with the specified version
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
        Utils
    */

    /*
        Performs macro substitution in the string. Returns the result where
        all values are replaced. Parameters are substituted using the given
        opening and closing keys. Case-sensitive.
    */
    public function prep
    (
        /* Value for macro substitutions */
        $aSource,
        array $aExternal = [],
        /* List of keys to exclude from substitution, left unchanged */
        array $aExclude = []
    )
    {
        return clPrep
        (
            $aSource,
            array_merge( $this -> getParams(), $aExternal ),
            $aExclude
        );
    }


    /*
        ПОдготоваливает с автозаменами и возвращает параметр по имени
    */
    public function prepParam
    (
        string $aParam,
        array $aExternal = [],
        array $aExclude = []
    )
    :string
    {
        return (string) $this -> prep
        (
            $this -> getParam( $aParam ),
            $aExternal,
            $aExclude
        );
    }



    /*
        Recursive replacement of values in files.
        The file contents are read, replacements are made, and the file is saved.
    */
    public function replace
    (
        /* File path for performing replacements */
        string $APath,
        /*
            Array of file mask strings; replacements are performed on files
            matching these masks
        */
        array $AIncludes = null,
        /*
            Array of file mask strings; files matching these masks are excluded
            from replacements
        */
        array $AExcludes = null,
        /* Keys to exclude by name */
        array $AExcludeKeys = null
    )
    {
        if( $this -> isOk() )
        {
            /* Filling inclusion and exclusion arrays */
            if( empty( $AIncludes ))    $AIncludes = [];
            if( empty( $AExcludes ))    $AExcludes = [];
            if( empty( $AExcludeKeys )) $AExcludeKeys = [];

            /* Preparing parameters of included files */
            foreach( $AIncludes as &$Item )
            {
                $Item = $this -> prep( $Item );
            }

            /* Preparing parameters of excluded files */
            foreach( $AExcludes as &$Item )
            {
                $Item = $this -> prep( $Item );
            }

            /* Path preparation */
            $Path = $this -> prep( $APath );

            /* Getting the list of values */
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
                        $Result = $this
                        -> prep( $Source, [], $AExcludeKeys );

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
                            $this
                            -> GetLog()
                            -> Text( '[r]', Console::ESC_INK_LIME );

                            $Replace++;
                            /* Сохранение результатов */
                            if( file_put_contents( $AFile, $Result ) === false )
                            {
                                /*
                                    Сообщение об ошибке в случае несохранения
                                    файла
                                */
                                $this
                                -> getLog()
                                -> Text( '[X]', Log::COLOR_ERROR );
                                $Error++;
                            }
                        }
                    }
                    else
                    {
                        /*
                            Log message when skipping a file based on
                            include/exclude masks
                        */
                        $this -> getLog() -> text( '[s]',  Log::COLOR_INFO );
                        $Skip++;
                    }
                    $this -> getLog() -> param( 'File', $AFile );
                }
            );

            if( ( $Error ) > 0 )
            {
                $this
                -> setResult( 'ErrorReplaceInFile', [ 'Count' => $Error ] );
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
        Execute CLI on local or remote host
        Deployment parameters used:
            REMOTE Remote host and user for SSH connection
            remote-ssl-key SSL key file for SSH access
    */
    public function shell
    (
        /* List of command lines to execute */
        array   $aLines,
        /* Execution comment */
        string  $aComment       = '',
        /* Result code that will be returned in any case */
        string  $aResultCode    = ''
    )
    {
$aRemote=false;
        if( $this -> isOk() )
        {
            $shell = Shell::create( $this -> GetLog() );

            $shell
            -> CmdBegin()
            -> SetComment( $aComment )
            ;

            if( $aRemote )
            {
                $shell
                -> setConnection( $this -> prep( '%remote%' ))
                -> setPrivateKeyPath( $this -> getParam( 'remote-ssl-key' ));
            }

            /* Line processing */
            $lines = [];
            foreach( $aLines as $line)
            {
                $lines[] = $this -> prep( $line );
            }

            $shell
            -> cmdAdd( implode( ' && ', $lines) )
            /*
                Command execution. Test mode is checked for both
                remote executor and local execution.
            */
            -> cmdEnd
            (
                ' ',
                $aRemote ? ! $this -> isFull() : $this -> isTest()
            )
            ;

            if( !empty( $aResultCode ))
            {
                $this -> setCode( $aResultCode );
            }
            else
            {
                $shell -> resultTo( $this );
            }
        }
        return $this;
    }



    /*
        Check if test mode is enabled via the parameter Mode=Test.
    */
    public function isTest()
    {
        $mode = $this -> getMode();
        return $mode != self::MODE_FULL && $mode != self::MODE_BUILD;
    }



    /*
        Check if full mode is enabled via the parameter mode=Full.
    */
    public function isFull()
    {
        return
        $this -> getMode() == self::MODE_FULL;
    }



    /*
        Output parameters to the log
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
        Returns a string with the given key before each array element.
        Example:
            KeyBeforeValue(' -key ', ['asd', 'dfg', 'dfg'])
            returns '-key asd -key dfg -key dfg'
    */
    private function keyBeforeValue
    (
        /* Ключ */
        string  $aKey,
        /* Массив значений */
        array   $aArray = [],
        /* Опциональные кавычки */
        string  $aQuote = ''
    )
    {
        $result = [];
        foreach( $aArray as $item )
        {
            $result[] = $aKey . $aQuote . $item . $aQuote;
        }
        return implode( '', $result );
    }



    /*
        Purification - removing folders of previous builds
    */
    public function purifyDestination()
    {
        return
        $this -> delete( '%dest%', 'Remove destination folder' );
    }



    /*
        Output information to the console
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

        File with specific settings containing passwords and other sensitive
        data. The file should be kept separate from the project and included
        during the build process by setting the %FOB_FILE% argument.
    */

    public function activateFob
    (
        string $a
    )
    {
        $json = json_decode
        (
            file_get_contents( $this -> prep( '%fob-file%' ) )
        );

        $this -> activeFob = clValueFromObject
        (
            empty( $json ) ? [] : $json, $a, []
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
        return $this -> activeFob;
    }




    /**************************************************************************
        Setters and getters
    */


    public function setMode
    (
        string $a
    )
    {
        $this -> mode = $a;
        return $this;
    }



    public function getMode()
    {
        return $this -> mode;
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
        return $this -> addParam
        ([
            /* SSH login for access to the target host */
            'remote-user'   => $aUser,
            /* Address of the target host, in this case deploying to localhost */
            'remote-host'   => $aHost,
            /* Port of the target host */
            'remote-port'   => $aPort
        ]);
    }



    /*
       Генерация файла из шаблона
    */
    public function genFile
    (
        /* Файл источник шаблона */
        string $aSource,
        /* Файл направление */
        string $aDest,
        /* Список инстансов 1,2,3,....*/
        string $aInstances
    )
    :self
    {
        $instances = explode( ',', $aInstances );
        foreach( $instances as $instance )
        {
            $instance = trim( $instance);
            $dest = $this -> prep( $aDest, ['instance' => $instance ]);
            $source = $this -> prep( $aSource, ['instance' => $instance ]);

            if
            (
                clCheckPath( pathinfo( $dest, PATHINFO_DIRNAME ))
                && file_exists( $source )
            )
            {
                $content = (string) file_get_contents( $source );
                $content = $this -> prep( $content, [ 'instance' => $instance ]);
                if( !file_put_contents( $dest, $content ))
                {
                    $this -> setResult
                    (
                        'gen-file-write-error',
                        [ 'dest' => $dest ]
                    );
                    break;
                }
            }
            else
            {
                $this -> setResult
                (
                    'gen-file-check-path-error',
                    [ 'dest' => $dest ]
                );
                break;
            }
        }
        return $this;
    }


    /*
        Создает список строк на основе шаблона и загружет его в ключ направления
        в указанном количестве
    */
    public function buildList
    (
        string $aDest,
        string $aTemplate,
        string $aInstances,
        string $aDelimiter = ','
    )
    {
        $loop = explode( ',', $aInstances );
        $template = $this -> prepParam( $aTemplate );
        $result = [];

        foreach( $loop as $instance )
        {
            $instance = trim( $instance );
            $line = $this -> prep( $template, [ 'instance' => $instance ]);
            $result[] = $this -> prep( '"'. $line . '"' );
        }
        $this -> setParam( $aDest, implode( $aDelimiter, $result )  );

        return $this;
    }



    /*
        Создает список строк и размещает его в указанном ключе
    */
    public function buildValues
    (
        /* Ключ направление */
        string $aDest,
        /* Key value array */
        array  $aArray,
        /* Delimiter */
        string $aDelimiter = ','
    )
    :self
    {
        $result = [];
        foreach ( $aArray as $value )
        {
            $result[] = ( string ) $value;
        }
        $this -> setParam( $aDest, implode( $aDelimiter, $result ) );
        return $this;
    }



    public function buildDockerVolumes
    (
        $aInstance
    )
    {
        $result = [];
        $volumes = $this -> getParam( 'docker-volumes', [] );

        if( !is_array( $volumes ))
        {
            $volumes = [ $volumes ];
        }

        foreach( $volumes as $line )
        {
            $result[] = '-v';
            $result[] = $this -> prep( $line, [ 'instance' => $aInstance ]);
        }

        return implode( ' ', $result );
    }



    /*
        Синхронизирует пути для множетсва инстансов
        Supports remote sync
    */
    public function syncInstances
    (
        /* Comment for operation */
        string $aCaption,
        /* List of instance 1,2,3,n....*/
        string $aInstances,
        /* Source path */
        string $aSrc,
        /* Destination path */
        string $aDst
    )
    : self
    {
        $instances = array_map( 'trim', explode( ',', $aInstances ));
        foreach( $instances as $instance )
        {
            $this -> sync
            (
                $aCaption,
                $this -> prep( $aSrc, [ 'instance' => $instance ]),
                $this -> prep( $aDst, [ 'instance' => $instance ])
            );
        }
        return $this;
    }



    /*
        Convert key value array in to value
    */
    public function keyValueToValue
    (
        array $aArray,
        string $aDelimiter = '='
    )
    : array
    {
        $result = [];
        foreach( $aArray as $key => $value )
        {
            $result[] = $key . $aDelimiter . $value;
        }
        return $result;
    }
}

