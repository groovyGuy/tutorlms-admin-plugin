<?php
/*
Plugin Name: Student Database Dashboard
Description: Adds a Database dashboard and student progress views integrated with TutorLMS.
Version: 0.9.1
Author: Mike Schmidt / OpenAI
*/

// ----------------------
// 5. SCHEDULE INACTIVITY CHECK
// ----------------------
if (!wp_next_scheduled('sdd_check_student_inactivity')) {
    wp_schedule_event(time(), 'daily', 'sdd_check_student_inactivity');
}

add_action('sdd_check_student_inactivity', function() {
    $students = get_users(['role__in' => ['subscriber','student']]);
    foreach ($students as $student) {
        $user_id = $student->ID;
        $last_login = get_user_meta($user_id, 'last_login', true);
        // Fallback: use Tutor LMS last activity if available
        if (function_exists('tutor_utils')) {
            $activity = tutor_utils()->get_last_activity($user_id);
            if ($activity) $last_login = max($last_login, strtotime($activity));
        }
        if (!$last_login) continue;
        if (time() - $last_login > 14 * 24 * 60 * 60) { // 2 weeks
            update_user_meta($user_id, 'status_aluno', 'Parado');
        } else {
            update_user_meta($user_id, 'status_aluno', 'Ativo');
        }
    }
});

// Track last login
add_action('wp_login', function($user_login, $user) {
    update_user_meta($user->ID, 'last_login', time());
}, 10, 2);

// ----------------------
// 4. SET DEFAULT STATUS ON REGISTRATION
// ----------------------
add_action('user_register', function($user_id) {
    update_user_meta($user_id, 'status_aluno', 'Ativo');
});

// ----------------------
// 1. ADMIN MENU
// ----------------------
add_action('admin_menu', 'student_database_admin_menu');
function student_database_admin_menu() {
    add_menu_page(
        __('Student Database Dashboard', 'school-admin'), 
        __('Database', 'school-admin'), 
        'manage_options', 
        'student-database-dashboard', 
        'student_database_admin_page', 
        'dashicons-database', 
        2
    );
    add_submenu_page(
        'student-database-dashboard',
        __('Reports', 'school-admin'),
        __('Reports', 'school-admin'),
        'manage_options',
        'student-database-reports',
        'student_database_reports_page'
    );
}

// ----------------------
// 2. ENQUEUE SELECT2
// ----------------------
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_student-database-dashboard') {
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');
        wp_add_inline_script('select2', '
            jQuery(document).ready(function($) {
                $("#student_select").select2();
            });
        ');
    }
});

// ----------------------
// 3. MAIN ADMIN PAGE
// ----------------------
function student_database_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'school-admin'));
    }
    $students = get_users(['role__in' => ['subscriber','student']]);
    $selected_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : '';
    $selected_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'progress';
    ob_start();
    echo '<div class="wrap"><h1>' . esc_html__('Student Database Dashboard', 'school-admin') . '</h1>';
    echo '<form method="GET" style="margin:20px 0;">';
    wp_nonce_field('student_db_select', 'student_db_nonce');
    echo '<input type="hidden" name="page" value="student-database-dashboard">';
    echo '<select name="student_id" id="student_select" style="width:300px;">';
    echo '<option value="">' . esc_html__('-- Select a Student --', 'school-admin') . '</option>';
    foreach ($students as $s) {
        $full_name = trim($s->first_name . ' ' . $s->last_name);
        if (empty($full_name)) {
            $full_name = $s->display_name;
        }
        $name_lc = strtolower($full_name);
        if (strpos($name_lc, 'test') !== false || strpos($name_lc, 'demo') !== false || strpos($name_lc, 'sample') !== false || strpos($name_lc, 'teste') !== false) {
            continue;
        }
        echo '<option value="'.esc_attr($s->ID).'" '.selected($selected_id, $s->ID, false).'>' . esc_html($full_name) . '</option>';
    }
    echo '</select> ';
    submit_button(esc_html__('View', 'school-admin'), 'primary', '', false);
    echo '</form>';
    if (!empty($selected_id) && isset($_GET['student_db_nonce']) && wp_verify_nonce($_GET['student_db_nonce'], 'student_db_select')) {
        $student_id = intval($selected_id);
        echo '<h2>' . esc_html__('Student:', 'school-admin') . ' ' . esc_html(get_the_author_meta('display_name', $student_id)) . '</h2>';
        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $membership = pmpro_getMembershipLevelForUser($student_id);
            if ($membership && isset($membership->name) && $membership->name) {
                echo '<p><strong>' . esc_html__('Nível de Associação:', 'school-admin') . '</strong> ' . esc_html($membership->name) . '</p>';
            } else {
                echo '<p><strong>' . esc_html__('Nível de Associação:', 'school-admin') . '</strong> <em>' . esc_html__('Não definido', 'school-admin') . '</em></p>';
            }
        } else {
            echo '<p><strong>' . esc_html__('Nível de Associação:', 'school-admin') . '</strong> <em>' . esc_html__('Plugin PMPro não ativo', 'school-admin') . '</em></p>';
        }
        $status_aluno = get_user_meta($student_id, 'status_aluno', true);
        if (!$status_aluno) $status_aluno = esc_html__('Ativo', 'school-admin');
        echo '<p><strong>' . esc_html__('Status do Aluno:', 'school-admin') . '</strong> ' . esc_html($status_aluno) . '</p>';
        $total_courses = 0;
        $completed_courses = 0;
        if (function_exists('tutor_utils')) {
            $courses = tutor_utils()->get_enrolled_courses_by_user($student_id);
            $enrolled_courses = [];
            if ($courses && $courses->have_posts()) {
                foreach ($courses->posts as $course) {
                    if ($course->post_status !== 'publish') continue;
                    $enrolled_courses[] = $course;
                }
            }
            $total_courses = count($enrolled_courses);
            foreach ($enrolled_courses as $course) {
                if (tutor_utils()->is_completed_course($course->ID, $student_id)) $completed_courses++;
            }
        }
        $percent_complete = ($total_courses > 0) ? round(($completed_courses / $total_courses) * 100) : 0;
        echo '<div style="margin:10px 0;padding:8px 12px;background:#eaf6ff;border-radius:6px;font-size:16px;">
            <strong>' . esc_html__('Progresso Geral:', 'school-admin') . '</strong> ' . esc_html($completed_courses) . '/' . esc_html($total_courses) . ' (' . esc_html($percent_complete) . '%)
        </div>';
        $tabs = [
            'progress' => esc_html__('Progresso Acadêmico', 'school-admin'),
            'status' => esc_html__('Status Acadêmico', 'school-admin'),
            'personal' => esc_html__('Dados Pessoais', 'school-admin'),
        ];
        echo '<h3 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $class = ($selected_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=student-database-dashboard&student_id='.$student_id.'&tab='.$slug.'&student_db_nonce='.esc_attr($_GET['student_db_nonce']));
            echo '<a href="'.$url.'" class="'.$class.'">'.esc_html($label).'</a>';
        }
        echo '</h3>';
        $tab_file = plugin_dir_path(__FILE__) . 'tabs/tab-'.$selected_tab.'.php';
        if (file_exists($tab_file)) {
            include $tab_file;
        } else {
            echo '<p><em>' . esc_html__('Tab content not found.', 'school-admin') . '</em></p>';
        }
    }
    echo '</div>';
    echo ob_get_clean();
}

// ----------------------
// 6. REPORTS PAGE
// ----------------------
function student_database_reports_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'school-admin'));
    }
    ob_start();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Relatórios Acadêmicos', 'school-admin'); ?></h1>
        <form id="reports-filters" style="margin-bottom:15px;">
            <?php wp_nonce_field('student_db_reports', 'student_db_reports_nonce'); ?>
            <label><strong><?php echo esc_html__('Status do Aluno:', 'school-admin'); ?></strong></label><br>
            <label><input type="checkbox" name="status[]" value="Ativo"> <?php echo esc_html__('Ativo', 'school-admin'); ?></label><br>
            <label><input type="checkbox" name="status[]" value="Parada"> <?php echo esc_html__('Parada', 'school-admin'); ?></label><br>
            <label><input type="checkbox" name="status[]" value="Concluído"> <?php echo esc_html__('Concluído', 'school-admin'); ?></label><br><br>
            <label for="nivel"><strong><?php echo esc_html__('Nível de Associação:', 'school-admin'); ?></strong></label><br>
            <select name="nivel" id="nivel">
                <option value=""><?php echo esc_html__('Todos', 'school-admin'); ?></option>
                <option value="Básico">Básico</option>
                <option value="Avançado">Avançado</option>
            </select><br><br>
            <label for="pagamento"><strong><?php echo esc_html__('Pagamento:', 'school-admin'); ?></strong></label><br>
            <select name="pagamento" id="pagamento">
                <option value=""><?php echo esc_html__('Todos', 'school-admin'); ?></option>
                <option value="Em dia">Em dia</option>
                <option value="Atrasado">Atrasado</option>
            </select><br><br>
            <label for="co_validacao"><strong><?php echo esc_html__('Co-validação:', 'school-admin'); ?></strong></label><br>
            <select name="co_validacao" id="co_validacao">
                <option value=""><?php echo esc_html__('Todos', 'school-admin'); ?></option>
                <option value="Sim">Sim</option>
                <option value="Não">Não</option>
            </select>
        </form>
        <div id="reports-results">
            <p><?php echo esc_html__('Selecione os filtros para ver os resultados.', 'school-admin'); ?></p>
        </div>
        <script>
        jQuery(document).ready(function($){
            function loadReports() {
                var data = {
                    action: 'sdd_load_reports',
                    status: [],
                    nivel: $('#nivel').val(),
                    pagamento: $('#pagamento').val(),
                    co_validacao: $('#co_validacao').val(),
                    student_db_reports_nonce: $('#student_db_reports_nonce').val()
                };
                $('input[name="status[]"]:checked').each(function(){
                    data.status.push($(this).val());
                });
                $.post(ajaxurl, data, function(response){
                    $('#reports-results').html(response);
                });
            }
            $('#nivel, #pagamento, #co_validacao').change(loadReports);
            $('input[name="status[]"]').change(loadReports);
        });
        </script>
    </div>
    <?php
    echo ob_get_clean();
}

add_action('wp_ajax_sdd_load_reports', 'sdd_load_reports');
function sdd_load_reports() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this data.', 'school-admin'));
    }
    check_ajax_referer('student_db_reports', 'student_db_reports_nonce');
    $statuses   = isset($_POST['status']) ? array_map('sanitize_text_field', (array) $_POST['status']) : [];
    $nivel      = isset($_POST['nivel']) ? sanitize_text_field($_POST['nivel']) : '';
    $pagamento  = isset($_POST['pagamento']) ? sanitize_text_field($_POST['pagamento']) : '';
    $cov        = isset($_POST['co_validacao']) ? sanitize_text_field($_POST['co_validacao']) : '';
    $students = get_users(['role__in' => ['subscriber','student']]);
    $total_students = count($students);
    $rows = [];
    foreach ($students as $student) {
        $id   = $student->ID;
        $name = trim($student->first_name . ' ' . $student->last_name);
        if (!$name) $name = $student->display_name;
        $status   = get_user_meta($id, 'status_aluno', true) ?: esc_html__('Ativo', 'school-admin');
        $nivelVal = get_user_meta($id, 'nivel_associacao', true) ?: esc_html__('Não definido', 'school-admin');
        $paga     = get_user_meta($id, 'pagamento', true) ?: esc_html__('Não informado', 'school-admin');
        $covVal   = get_user_meta($id, 'co_validacao', true) ?: esc_html__('Não informado', 'school-admin');
        $total = $done = 0;
        if (function_exists('tutor_utils')) {
            $courses = tutor_utils()->get_enrolled_courses_by_user($id);
            if ($courses && $courses->have_posts()) {
                $total = count($courses->posts);
                foreach ($courses->posts as $c) {
                    if (tutor_utils()->is_completed_course($c->ID, $id)) $done++;
                }
            }
        }
        $progress = $total > 0 ? round(($done/$total)*100) : 0;
        if ($statuses && !in_array(strtolower($status), array_map('strtolower', $statuses))) continue;
        if ($nivel && strtolower($nivelVal) !== strtolower($nivel)) continue;
        if ($pagamento && strtolower($paga) !== strtolower($pagamento)) continue;
        if ($cov && strtolower($covVal) !== strtolower($cov)) continue;
        $rows[] = [
            'name'      => $name,
            'status'    => $status,
            'nivel'     => $nivelVal,
            'pagamento' => $paga,
            'cov'       => $covVal,
            'progress'  => esc_html($done) . '/' . esc_html($total) . ' ' . esc_html__('cursos', 'school-admin') . ' (' . esc_html($progress) . '%)'
        ];
    }
    $filtered_count = count($rows);
    $percentage = $total_students > 0 ? round(($filtered_count / $total_students) * 100, 2) : 0;
    echo '<p>' . esc_html__('Total:', 'school-admin') . ' ' . esc_html($filtered_count) . '/' . esc_html($total_students) . ' (' . esc_html($percentage) . '%)</p>';
    if ($rows) {
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Nome', 'school-admin') . '</th><th>' . esc_html__('Status', 'school-admin') . '</th><th>' . esc_html__('Pagamento', 'school-admin') . '</th><th>' . esc_html__('Co-validação', 'school-admin') . '</th><th>' . esc_html__('Nível', 'school-admin') . '</th><th>' . esc_html__('Progresso', 'school-admin') . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>'.esc_html($r['name']).'</td>';
            echo '<td>'.esc_html($r['status']).'</td>';
            echo '<td>'.esc_html($r['pagamento']).'</td>';
            echo '<td>'.esc_html($r['cov']).'</td>';
            echo '<td>'.esc_html($r['nivel']).'</td>';
            echo '<td>'.esc_html($r['progress']).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>' . esc_html__('Nenhum resultado encontrado.', 'school-admin') . '</p>';
    }
    wp_die();
}
