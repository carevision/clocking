<header class="colored">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="logo">
                    <a href="#">
                        <img src="{{ asset('images/loginPage-logo.png') }}" alt="CareVision">
                    </a>
                </div>
                <ul class="headerNav list-unstyled">
                    <li class="{{ (\Request::route()->getName() == 'terminal.add' || \Request::route()->getName() == 'home') ? 'active' : '' }} proceed_to_orders_link">
                        <a href="{{ route('terminal.add') }}">Add New Terminal</a>
                    </li>
                    <li class="{{ (\Request::route()->getName() == 'force.sync') ? 'active' : '' }}">
                        <a href="{{ route('force.sync') }}">
                            Force Sync
                        </a>
                    </li>
                    <li class="{{ (\Request::route()->getName() == 'database.backup') ? 'active' : '' }}">
                        <a href="{{ route('database.backup') }}">
                            Database Backup
                        </a>
                    </li>
                </ul>
                <ul class="headerNav list-unstyled">
                </ul>
                <section class="pull-right">
                    <article class="date">
                        <span>
                            <?php echo date('M dS, Y');?>
                        </span>
                    </article>

                    <div class="dropdown">
                        <button type="button" id="dropdownMenu1" data-toggle="dropdown" aria-haspopup="true"
                                aria-expanded="true">
                            <span class="caret"></span>
                        </button>
                        <ul class="dropdown-menu" aria-labelledby="dropdownMenu1">
                            <li><a href="{{ route('logout') }}">Sign Out</a></li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </div>
</header>
